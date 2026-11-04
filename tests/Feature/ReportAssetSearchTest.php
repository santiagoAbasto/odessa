<?php

namespace Tests\Feature;

use App\Enums\ReportStatus;
use App\Models\Asset;
use App\Models\CatalogItem;
use App\Models\InspectionReport;
use App\Models\Organization;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Selector de equipos de un informe, con búsqueda en el servidor.
 *
 * Antes traía sólo los primeros 300 de la empresa y filtraba en el navegador:
 * con 489 equipos (HASA) los últimos del orden alfabético no se podían agregar
 * a ningún informe. Es el mismo defecto del desplegable de envíos.
 */
class ReportAssetSearchTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private InspectionReport $informe;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();

        $this->org = $this->organization();
        $this->informe = $this->reportFor($this->asset($this->org, ['description' => 'Ya en el informe']), status: ReportStatus::Draft);
    }

    private function taller(): User
    {
        return $this->staffUser(Permissions::ROLE_WORKSHOP);
    }

    /** @return list<string> ids públicos ofrecidos */
    private function buscar(string $q = ''): array
    {
        return collect(
            $this->actingAs($this->taller())
                ->getJson("/informes/{$this->informe->public_id}/equipos-disponibles?q=".urlencode($q))
                ->assertOk()
                ->json('assets')
        )->pluck('id')->all();
    }

    /** El defecto que había: pasado el tope, el equipo no se podía agregar a ningún informe. */
    public function test_alcanza_un_equipo_aunque_la_empresa_tenga_cientos(): void
    {
        foreach (range(1, 120) as $n) {
            $this->asset($this->org, ['description' => sprintf('Equipo relleno %03d', $n), 'serial_number' => "REL-{$n}"]);
        }
        $ultimo = $this->asset($this->org, ['description' => 'Zzz el último del orden', 'serial_number' => 'ALCANZAME-1']);

        $this->assertSame([$ultimo->public_id], $this->buscar('ALCANZAME'));
    }

    public function test_busca_por_descripcion_serie_codigo_y_numero_de_parte(): void
    {
        $tipo = CatalogItem::create(['kind' => Asset::KIND_PART, 'description' => 'Preventor', 'part_number' => 'PN-INF-55']);
        $a = $this->asset($this->org, ['description' => 'Válvula única', 'serial_number' => 'S-A']);
        $b = $this->asset($this->org, ['description' => 'B', 'serial_number' => 'SERIE-RARA-7']);
        $c = $this->asset($this->org, ['description' => 'C', 'serial_number' => 'S-C', 'code' => 'COD-RARO-3']);
        $d = $this->asset($this->org, ['description' => 'D', 'serial_number' => 'S-D', 'catalog_item_id' => $tipo->getKey()]);

        $this->assertSame([$a->public_id], $this->buscar('única'));
        $this->assertSame([$b->public_id], $this->buscar('SERIE-RARA'));
        $this->assertSame([$c->public_id], $this->buscar('COD-RARO'));
        $this->assertSame([$d->public_id], $this->buscar('PN-INF'));
    }

    public function test_sin_texto_devuelve_una_tanda_acotada(): void
    {
        foreach (range(1, 80) as $n) {
            $this->asset($this->org, ['description' => sprintf('Equipo %03d', $n)]);
        }

        $this->assertCount(50, $this->buscar(''));
    }

    public function test_no_ofrece_los_que_ya_estan_en_el_informe_ni_los_de_otra_empresa(): void
    {
        $libre = $this->asset($this->org, ['description' => 'Bomba libre']);
        $this->asset($this->organization(), ['description' => 'Bomba ajena']);

        $this->assertSame([$libre->public_id], $this->buscar('Bomba'));
        $this->assertSame([], $this->buscar('Ya en el informe'));
    }

    public function test_un_cliente_no_puede_usar_el_buscador(): void
    {
        $this->actingAs($this->clientUser($this->org, Permissions::ROLE_CLIENT_OPERATOR))
            ->getJson("/informes/{$this->informe->public_id}/equipos-disponibles?q=x")
            // 404 y no 403: el informe está en borrador y el cliente no lo ve (DraftReportVisibilityTest).
            ->assertNotFound();
    }
}
