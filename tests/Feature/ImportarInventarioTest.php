<?php

namespace Tests\Feature;

use App\Actions\ImportarInventario;
use App\Enums\AssetStatus;
use App\Models\Asset;
use App\Models\CatalogItem;
use App\Models\Manufacturer;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Carga del maestro de productos y los equipos desde las filas del Excel.
 */
class ImportarInventarioTest extends TestCase
{
    use RefreshDatabase;

    private function cargar(): array
    {
        $org = Organization::create(['name' => 'HASA', 'short_code' => 'HASA', 'is_active' => true]);

        $cos = Carbon::today()->subMonths(2)->toDateString();
        $equipos = [
            // enganchado + certificado (COS reciente -> apto)
            ['HASA', 'REF-1', 'SERIE-A', 'L-100', 'ELMAR', 'desc cli', 'SB / GIE', 'ELMAR, SB / GIE, ACME', 'ELMAR', '0', '0', 'ACME 2in', '0', '10M', '15M', $cos, '', ''],
            // enganchado sin COS -> sin certificar
            ['HASA', 'REF-2', 'SERIE-B', 'L-100', 'ELMAR', 'desc cli', 'SB / GIE', 'ELMAR, SB / GIE, ACME', 'ELMAR', '0', '0', 'ACME 2in', '0', '10M', '15M', '', '', ''],
            // serie duplicada 13838266 (dos filas) -> ambas afuera
            ['HASA', 'REF-3', '13838266', 'L-100', 'ELMAR', 'x', 'SB / GIE', 'ELMAR, SB / GIE, ACME', 'ELMAR', '0', '0', '0', '0', '10M', '15M', $cos, '', ''],
            ['HASA', 'REF-4', '13838266', 'L-100', 'ELMAR', 'x', 'SB / GIE', 'ELMAR, SB / GIE, ACME', 'ELMAR', '0', '0', '0', '0', '10M', '15M', $cos, '', ''],
            // sin número de serie -> afuera
            ['HASA', 'REF-5', '', 'L-100', 'ELMAR', 'x', 'SB / GIE', 'ELMAR, SB / GIE, ACME', 'ELMAR', '0', '0', '0', '0', '10M', '15M', $cos, '', ''],
        ];

        $conteo = app(ImportarInventario::class)($org, $equipos, ['13838266']);

        return [$org, $conteo];
    }

    public function test_crea_modelos_y_engancha_equipos_por_descripcion(): void
    {
        [$org, $conteo] = $this->cargar();

        $this->assertSame(['modelos' => 1, 'equipos' => 2, 'excluidos' => 3, 'sin_certificar' => 1], $conteo);
        $this->assertSame(1, CatalogItem::count());

        $apto = Asset::where('serial_number', 'SERIE-A')->firstOrFail();
        $this->assertNotNull($apto->catalog_item_id, 'El equipo tiene que enganchar con su modelo.');
        $this->assertSame('ELMAR', Manufacturer::find($apto->manufacturer_id)->name);
        $this->assertSame('L-100', $apto->manufacturer_reference);
        $this->assertSame('REF-1', $apto->client_reference);
    }

    public function test_el_cos_deja_el_equipo_apto_con_vencimiento(): void
    {
        [$org] = $this->cargar();

        $apto = Asset::where('serial_number', 'SERIE-A')->firstOrFail();
        $this->assertSame(AssetStatus::Fit, $apto->status);
        $this->assertNotNull($apto->expires_at);
        $this->assertTrue($apto->expires_at->isFuture());
    }

    public function test_sin_cos_queda_sin_certificar(): void
    {
        [$org] = $this->cargar();

        $sinCert = Asset::where('serial_number', 'SERIE-B')->firstOrFail();
        $this->assertSame(AssetStatus::Unknown, $sinCert->status);
        $this->assertNull($sinCert->expires_at);
    }

    public function test_la_serie_duplicada_y_la_vacia_no_se_importan(): void
    {
        [$org] = $this->cargar();

        $this->assertSame(0, Asset::where('serial_number', '13838266')->count());
        $this->assertSame(2, Asset::count());
    }
}
