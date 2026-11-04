<?php

namespace Tests\Feature;

use App\Actions\RecalculateAssetStatus;
use App\Enums\InspectionResult;
use App\Enums\ReportStatus;
use App\Models\Asset;
use App\Models\AssetSet;
use App\Models\Organization;
use App\Models\Reception;
use App\Models\Service;
use App\Models\Shipment;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

/**
 * Recorrida de todas las pantallas, con datos reales y cuatro tipos de usuario.
 *
 * No verifica reglas de negocio —eso lo hace cada prueba específica— sino lo
 * que una prueba puntual no ve: que ninguna pantalla termine en error 500.
 * Existe porque un 500 es silencioso en las pruebas de un solo endpoint
 * (`assertSessionHasNoErrors` pasa con uno), y porque cada cambio toca modelos
 * que usan muchas pantallas a la vez.
 *
 * Las partes diferidas (la trazabilidad, los catálogos, el panel) se piden
 * también, como las pide el navegador en una segunda consulta.
 */
class PagesSmokeTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    /** @var list<string> */
    private array $urls = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        Storage::fake(config('odessa.documents_disk'));

        $this->org = $this->organization();
        $cos = Service::create(['code' => 'COS', 'name' => 'Certificación anual', 'determines_fitness' => true, 'validity_days' => 365]);

        $pieza = $this->asset($this->org, ['kind' => Asset::KIND_PART, 'serial_number' => 'SMOKE-1']);
        $informe = $this->reportFor($pieza, InspectionResult::Apto, now()->subMonth()->toDateString(), now()->addYear()->toDateString());
        $informe->forceFill(['service_id' => $cos->getKey(), 'number' => 'SMK-FINAL'])->save();
        $informe->lines()->update(['service_id' => $cos->getKey()]);
        $this->document($informe);
        app(RecalculateAssetStatus::class)($pieza);

        $borrador = $this->reportFor($this->asset($this->org), status: ReportStatus::Draft);
        $borrador->forceFill(['number' => 'SMK-BORRADOR'])->save();

        $set = $this->asset($this->org, ['kind' => Asset::KIND_GENERIC, 'is_set' => true, 'description' => 'Set smoke']);
        $suelta = $this->asset($this->org, ['serial_number' => 'SMOKE-2']);
        $suelta->forceFill(['parent_id' => $set->getKey()])->save();
        app(RecalculateAssetStatus::class)($set->refresh());

        $envio = Shipment::create(['organization_id' => $this->org->getKey(), 'reference' => 'ENV-SMOKE-1']);
        $envio->items()->create(['asset_id' => $pieza->getKey(), 'is_urgent' => false]);
        $reception = Reception::create(['organization_id' => $this->org->getKey(), 'asset_id' => $pieza->getKey(), 'received_at' => now()->toDateString()]);
        $legacy = AssetSet::create(['organization_id' => $this->org->getKey(), 'name' => 'Set viejo smoke']);

        $this->urls = [
            '/panel', '/activos', '/activos?kind=generic', '/activos/exportar', '/sets', '/informes', '/envios',
            '/reportes', '/reportes/exportar', '/taller/recepciones', '/cuenta/clave',
            '/administracion/usuarios', '/administracion/organizaciones', '/administracion/auditoria',
            '/administracion/operaciones', '/administracion/productos', '/administracion/solicitudes',
            "/activos/{$pieza->public_id}", "/activos/{$set->public_id}", "/activos/{$suelta->public_id}",
            "/informes/{$informe->public_id}", "/informes/{$borrador->public_id}",
            "/sets/{$legacy->public_id}", "/envios/{$envio->public_id}",
            "/activos/{$set->public_id}/piezas-disponibles?q=smoke",
            "/envios/{$envio->public_id}/equipos-disponibles?q=smoke",
            "/informes/{$informe->public_id}/equipos-disponibles?q=smoke",
            "/taller/recepciones/piezas/{$this->org->public_id}?q=smoke",
        ];
        unset($reception);
    }

    /** @return array<string, array{string}> */
    public static function roles(): array
    {
        return [
            'superadministrador' => [Permissions::ROLE_SUPER_ADMIN],
            'taller' => [Permissions::ROLE_WORKSHOP],
            'cliente operador' => [Permissions::ROLE_CLIENT_OPERATOR],
            'cliente consulta' => [Permissions::ROLE_CLIENT_VIEWER],
        ];
    }

    private function usuario(string $rol): User
    {
        return in_array($rol, [Permissions::ROLE_CLIENT_OPERATOR, Permissions::ROLE_CLIENT_VIEWER], true)
            ? $this->clientUser($this->org, $rol)
            : $this->staffUser($rol);
    }

    /**
     * Pide la página y, si trae partes diferidas, también esas, como lo hace el
     * navegador. Un error de cualquiera de las dos es un error de la pantalla.
     */
    private function visitar(string $url): int
    {
        $respuesta = $this->get($url);
        $estado = $respuesta->getStatusCode();

        // Descargas (Excel): el contenido se produce al leerlo; un 500 estaría adentro.
        if ($respuesta->baseResponse instanceof StreamedResponse) {
            $respuesta->streamedContent();

            return $estado;
        }

        if ($estado === 200 && $respuesta->headers->get('content-type') && str_contains($respuesta->headers->get('content-type'), 'html')) {
            $pagina = $respuesta->viewData('page') ?? [];
            $diferidas = collect($pagina['deferredProps'] ?? [])->flatten()->all();

            if ($diferidas !== []) {
                $parcial = $this->get($url, [
                    'X-Inertia' => 'true',
                    'X-Inertia-Version' => $pagina['version'] ?? '',
                    'X-Inertia-Partial-Component' => $pagina['component'],
                    'X-Inertia-Partial-Data' => implode(',', $diferidas),
                ]);
                $estado = max($estado, $parcial->getStatusCode());
            }
        }

        return $estado;
    }

    #[DataProvider('roles')]
    public function test_ninguna_pantalla_termina_en_error_de_servidor(string $rol): void
    {
        $this->actingAs($this->usuario($rol));
        $fallas = [];

        foreach ($this->urls as $url) {
            $estado = $this->visitar($url);

            if ($estado >= 500) {
                $fallas[] = "{$estado} {$url}";
            }
        }

        $this->assertSame([], $fallas, "Pantallas con error de servidor para «{$rol}»:\n".implode("\n", $fallas));
    }

    /** El superadministrador alcanza todo: si algo le da 403/404 hay una ruta o un permiso mal armado. */
    public function test_el_superadministrador_abre_todas_las_pantallas(): void
    {
        $this->actingAs($this->staffUser(Permissions::ROLE_SUPER_ADMIN));
        $fallas = [];

        foreach ($this->urls as $url) {
            // Quien no tiene que cambiar su contraseña es redirigido: es lo correcto, no un error.
            if ($url === '/cuenta/clave') {
                continue;
            }

            $estado = $this->visitar($url);

            if ($estado !== 200) {
                $fallas[] = "{$estado} {$url}";
            }
        }

        $this->assertSame([], $fallas, "Pantallas que no abren para el superadministrador:\n".implode("\n", $fallas));
    }
}
