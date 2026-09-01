<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Support\Permissions;
use App\Support\SpreadsheetExport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Exportación de activos a Excel.
 *
 * Lo que más importa acá no es que el archivo se genere, sino que no se lleve
 * datos de otra empresa: es una descarga, y lo que sale ya no vuelve.
 */
class AssetExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    /**
     * Texto real de la planilla.
     *
     * Un .xlsx es un zip: buscar una cadena en el binario descargado no
     * encuentra nada porque el contenido va comprimido. Hay que abrirlo y
     * leer la hoja y la tabla de cadenas compartidas.
     */
    private function textoDeLaPlanilla(string $descarga): string
    {
        // Sin zip la descarga es un CSV: el texto ya viene plano.
        if (! SpreadsheetExport::supportsXlsx()) {
            return $descarga;
        }

        $ruta = tempnam(sys_get_temp_dir(), 'export').'.xlsx';
        file_put_contents($ruta, $descarga);

        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($ruta) === true, 'El archivo descargado no es un xlsx válido.');

        $texto = (string) $zip->getFromName('xl/sharedStrings.xml')
            .(string) $zip->getFromName('xl/worksheets/sheet1.xml');

        $zip->close();
        @unlink($ruta);

        return $texto;
    }

    public function test_la_descarga_devuelve_un_xlsx(): void
    {
        if (! SpreadsheetExport::supportsXlsx()) {
            $this->markTestSkipped('Este servidor no tiene la extensión zip.');
        }

        $response = $this->actingAs($this->staffUser(Permissions::ROLE_WORKSHOP))
            ->get('/activos/exportar');

        $response->assertOk();
        $response->assertHeader(
            'content-type',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
        );
        $this->assertStringContainsString('.xlsx', $response->headers->get('content-disposition'));
    }

    /**
     * Un .xlsx es un ZIP por dentro. En un servidor sin la extensión `zip` no
     * hay forma de generarlo, y devolverle un error 500 a quien sólo quería su
     * listado sería lo peor que puede pasar. Se degrada a CSV, que Excel abre
     * igual, y el nombre del archivo acompaña el cambio.
     */
    public function test_sin_la_extension_zip_la_descarga_cae_a_csv(): void
    {
        // Se fuerza el respaldo en vez de saltar la prueba: es el camino que
        // va a correr en el servidor de producción, así que tiene que estar
        // cubierto aunque esta máquina sí tenga la extensión.
        $response = SpreadsheetExport::download(
            filename: 'piezas.xlsx',
            headings: ['Código', 'Descripción', 'Número de serie'],
            rows: fn () => [['A-1', 'Válvula de compuerta', 'SN-001']],
            asXlsx: false,
        );

        $this->assertStringContainsString('text/csv', $response->headers->get('content-type'));
        $this->assertStringContainsString('piezas.csv', $response->headers->get('content-disposition'));

        ob_start();
        $response->sendContent();
        $contenido = ob_get_clean();

        // El BOM es lo que hace que Excel reconozca el UTF-8 al abrirlo.
        $this->assertStringStartsWith("\xEF\xBB\xBF", $contenido);
        $this->assertStringContainsString('Número de serie', $contenido);
        $this->assertStringContainsString('Válvula de compuerta', $contenido);
        $this->assertStringContainsString('SN-001', $contenido);
    }

    public function test_el_archivo_generado_es_un_xlsx_valido_con_sus_encabezados(): void
    {
        if (! SpreadsheetExport::supportsXlsx()) {
            $this->markTestSkipped('Este servidor no tiene la extensión zip.');
        }

        $texto = $this->textoDeLaPlanilla(
            $this->actingAs($this->staffUser(Permissions::ROLE_WORKSHOP))
                ->get('/activos/exportar')
                ->streamedContent()
        );

        $this->assertStringContainsString('Número de serie', $texto);
        $this->assertStringContainsString('Días para vencer', $texto);
    }

    public function test_un_cliente_solo_exporta_las_piezas_de_su_empresa(): void
    {
        $propia = $this->organization('PROPIA');
        $ajena = $this->organization('AJENA');

        $this->asset($propia, ['kind' => Asset::KIND_PART, 'serial_number' => 'SERIE-PROPIA-001']);
        $this->asset($ajena, ['kind' => Asset::KIND_PART, 'serial_number' => 'SERIE-AJENA-999']);

        $texto = $this->textoDeLaPlanilla(
            $this->actingAs($this->clientUser($propia, Permissions::ROLE_CLIENT_VIEWER))
                ->get('/activos/exportar')
                ->streamedContent()
        );

        $this->assertStringContainsString('SERIE-PROPIA-001', $texto);
        $this->assertStringNotContainsString('SERIE-AJENA-999', $texto);
    }

    public function test_la_exportacion_respeta_los_filtros_de_la_pantalla(): void
    {
        $organizacion = $this->organization();

        $this->asset($organizacion, ['kind' => Asset::KIND_PART, 'serial_number' => 'BUSCADA-123']);
        $this->asset($organizacion, ['kind' => Asset::KIND_PART, 'serial_number' => 'IGNORADA-456']);

        $texto = $this->textoDeLaPlanilla(
            $this->actingAs($this->staffUser(Permissions::ROLE_WORKSHOP))
                ->get('/activos/exportar?serial=BUSCADA')
                ->streamedContent()
        );

        $this->assertStringContainsString('BUSCADA-123', $texto);
        $this->assertStringNotContainsString('IGNORADA-456', $texto);
    }

    public function test_la_descarga_queda_registrada_en_la_auditoria(): void
    {
        $usuario = $this->staffUser(Permissions::ROLE_WORKSHOP);

        $this->actingAs($usuario)->get('/activos/exportar')->streamedContent();

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $usuario->getKey(),
            'action' => 'asset.exported',
        ]);
    }
}
