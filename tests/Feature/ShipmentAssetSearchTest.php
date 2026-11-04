<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\CatalogItem;
use App\Models\Organization;
use App\Models\Shipment;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Buscador predictivo de equipos en «Envíos al taller».
 *
 * Pedido de Daniel (04/11/2026): un desplegable no sirve con miles de
 * equipos. Era peor que incómodo: el desplegable traía sólo los primeros 500
 * de la empresa, así que el resto directamente no se podía elegir.
 */
class ShipmentAssetSearchTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Shipment $envio;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();

        $this->org = $this->organization();
        $this->envio = Shipment::create([
            'organization_id' => $this->org->getKey(),
            'reference' => 'ENV-2026-0001',
        ]);
    }

    private function operador(): User
    {
        return $this->clientUser($this->org, Permissions::ROLE_CLIENT_OPERATOR);
    }

    /** @return list<string> ids públicos encontrados */
    private function buscar(string $q = '', ?User $usuario = null): array
    {
        $respuesta = $this->actingAs($usuario ?? $this->operador())
            ->getJson("/envios/{$this->envio->public_id}/equipos-disponibles?q=".urlencode($q))
            ->assertOk();

        return collect($respuesta->json('options'))->pluck('id')->all();
    }

    public function test_encuentra_el_equipo_por_descripcion_serie_codigo_y_numero_de_parte(): void
    {
        $tipo = CatalogItem::create(['kind' => Asset::KIND_PART, 'description' => 'Preventor anular', 'part_number' => 'PN-ZZ-77']);
        $porDescripcion = $this->asset($this->org, ['description' => 'Válvula de compuerta', 'serial_number' => 'S-1', 'code' => 'C-1']);
        $porSerie = $this->asset($this->org, ['description' => 'Otro', 'serial_number' => 'SERIE-UNICA-42', 'code' => 'C-2']);
        $porCodigo = $this->asset($this->org, ['description' => 'Otro más', 'serial_number' => 'S-3', 'code' => 'CODIGO-RARO-9']);
        $porParte = $this->asset($this->org, ['description' => 'Pieza', 'serial_number' => 'S-4', 'code' => 'C-4', 'catalog_item_id' => $tipo->getKey()]);

        $this->assertSame([$porDescripcion->public_id], $this->buscar('compuerta'));
        $this->assertSame([$porSerie->public_id], $this->buscar('SERIE-UNICA'));
        $this->assertSame([$porCodigo->public_id], $this->buscar('CODIGO-RARO'));
        $this->assertSame([$porParte->public_id], $this->buscar('PN-ZZ'));
    }

    /** El defecto que había: con miles de equipos, los que pasaban del 500 no se podían elegir. */
    public function test_alcanza_a_un_equipo_aunque_la_empresa_tenga_muchos(): void
    {
        foreach (range(1, 30) as $n) {
            $this->asset($this->org, ['description' => sprintf('Equipo relleno %03d', $n), 'serial_number' => "RELLENO-{$n}"]);
        }
        $buscado = $this->asset($this->org, ['description' => 'Zzz último en orden alfabético', 'serial_number' => 'ENCONTRAME-001']);

        $this->assertSame([$buscado->public_id], $this->buscar('ENCONTRAME'));
    }

    public function test_sin_texto_devuelve_una_tanda_acotada_y_no_toda_la_empresa(): void
    {
        foreach (range(1, 40) as $n) {
            $this->asset($this->org, ['description' => sprintf('Equipo %03d', $n)]);
        }

        $this->assertCount(20, $this->buscar(''));
    }

    public function test_no_ofrece_equipos_de_otra_empresa(): void
    {
        $propio = $this->asset($this->org, ['description' => 'Compartido propio']);
        $this->asset($this->organization(), ['description' => 'Compartido ajeno']);

        $this->assertSame([$propio->public_id], $this->buscar('Compartido'));
    }

    public function test_no_ofrece_un_equipo_que_ya_esta_declarado_en_este_envio(): void
    {
        $declarado = $this->asset($this->org, ['description' => 'Bomba declarada']);
        $libre = $this->asset($this->org, ['description' => 'Bomba libre']);
        $this->envio->items()->create(['asset_id' => $declarado->getKey(), 'is_urgent' => false]);

        $this->assertSame([$libre->public_id], $this->buscar('Bomba'));
    }

    public function test_un_cliente_no_puede_buscar_en_el_envio_de_otra_empresa(): void
    {
        $ajeno = $this->clientUser($this->organization(), Permissions::ROLE_CLIENT_OPERATOR);

        $this->actingAs($ajeno)
            ->getJson("/envios/{$this->envio->public_id}/equipos-disponibles?q=x")
            ->assertNotFound();
    }

    public function test_quien_no_puede_cargar_envios_no_busca(): void
    {
        $consulta = $this->clientUser($this->org, Permissions::ROLE_CLIENT_VIEWER);

        $this->actingAs($consulta)
            ->getJson("/envios/{$this->envio->public_id}/equipos-disponibles?q=x")
            ->assertForbidden();
    }

    public function test_cada_resultado_trae_etiqueta_con_descripcion_y_serie(): void
    {
        $this->asset($this->org, ['description' => 'Válvula X', 'serial_number' => 'SN-777', 'code' => 'COD-7']);

        $opcion = $this->actingAs($this->operador())
            ->getJson("/envios/{$this->envio->public_id}/equipos-disponibles?q=SN-777")
            ->json('options.0');

        $this->assertStringContainsString('Válvula X', $opcion['label']);
        $this->assertStringContainsString('SN-777', $opcion['label']);
        $this->assertSame('COD-7', $opcion['code']);
    }
}
