<?php

namespace Tests\Feature;

use App\Enums\InspectionResult;
use App\Models\InspectionReportAsset;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Listado de informes: se busca por lo que se inspeccionó (serie, referencia del
 * cliente, código) y cada fila muestra las series de todos sus equipos
 * (pedido del 05/11/2026).
 */
class InformesBusquedaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    /** Filas que devuelve el listado para esa consulta. */
    private function listado(string $consulta = ''): array
    {
        return $this->actingAs($this->staffUser(Permissions::ROLE_WORKSHOP))
            ->get('/informes'.$consulta)
            ->viewData('page')['props']['reports']['data'];
    }

    /** Un informe con varios equipos. */
    private function informeConEquipos(array ...$equipos)
    {
        $organizacion = $this->organization();
        $piezas = array_map(fn (array $a) => $this->asset($organizacion, $a), $equipos);
        $informe = $this->reportFor($piezas[0]);

        foreach (array_slice($piezas, 1) as $pieza) {
            InspectionReportAsset::create([
                'inspection_report_id' => $informe->getKey(),
                'asset_id' => $pieza->getKey(),
                'result' => InspectionResult::Apto->value,
            ]);
        }

        return $informe;
    }

    public function test_cada_fila_trae_las_series_de_todos_sus_equipos(): void
    {
        $informe = $this->informeConEquipos(
            ['serial_number' => 'SN-001', 'client_reference' => 'REF-A'],
            ['serial_number' => 'SN-002', 'client_reference' => 'REF-A'],
            ['serial_number' => null, 'code' => 'COD-9', 'client_reference' => 'REF-B'],
        );

        $fila = collect($this->listado())->firstWhere('number', $informe->number);

        $this->assertEqualsCanonicalizing(['SN-001', 'SN-002', 'COD-9'], $fila['serials']);
        $this->assertEqualsCanonicalizing(['REF-A', 'REF-B'], $fila['clientReferences']);
    }

    public function test_el_buscador_encuentra_el_informe_por_serie_referencia_o_codigo(): void
    {
        $buscado = $this->informeConEquipos(
            ['serial_number' => 'SN-BUSCADO', 'client_reference' => 'REF-X'],
            ['serial_number' => 'SN-OTRO', 'client_reference' => 'REF-Y', 'code' => 'COD-Y'],
        );
        $otro = $this->informeConEquipos(['serial_number' => 'SN-AJENO', 'client_reference' => 'REF-Z']);

        foreach (['SN-OTRO', 'REF-Y', 'COD-Y', $buscado->number] as $consulta) {
            $numeros = collect($this->listado('?search='.$consulta))->pluck('number');

            $this->assertTrue($numeros->contains($buscado->number), "No encontró por «{$consulta}»");
            $this->assertFalse($numeros->contains($otro->number), "Trajo de más por «{$consulta}»");
        }
    }

    public function test_los_filtros_de_serie_y_referencia_del_cliente(): void
    {
        $buscado = $this->informeConEquipos(['serial_number' => 'SN-777', 'client_reference' => 'REF-777']);
        $otro = $this->informeConEquipos(['serial_number' => 'SN-888', 'client_reference' => 'REF-888']);

        $porSerie = collect($this->listado('?serial=SN-777'))->pluck('number');
        $porReferencia = collect($this->listado('?client_reference=REF-777'))->pluck('number');

        $this->assertEquals([$buscado->number], $porSerie->all());
        $this->assertEquals([$buscado->number], $porReferencia->all());
        $this->assertFalse($porSerie->contains($otro->number));
    }
}
