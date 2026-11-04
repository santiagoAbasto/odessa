<?php

namespace Tests\Feature;

use App\Actions\RecalculateAssetStatus;
use App\Enums\InspectionResult;
use App\Models\Asset;
use App\Models\CatalogItem;
use App\Models\Organization;
use App\Models\Service;
use App\Models\User;
use App\Support\Permissions;
use App\Support\SpreadsheetExport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Planillas de vencimientos (pedido de Daniel, 04/11/2026).
 *
 * El negocio bajó el Excel y le faltaban cosas: el vencimiento no aparecía en
 * los equipos no aptos, no traía qué inspección vence ni cuándo (COS y COC), le
 * faltaba el número de parte, y el reporte de vencimientos no traía todos los
 * equipos. Se lee la planilla real, celda por celda: que el archivo se genere
 * no prueba nada sobre lo que dice.
 */
class ExportCertificationsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Service $cos;

    private Service $coc;

    protected function setUp(): void
    {
        parent::setUp();

        if (! SpreadsheetExport::supportsXlsx()) {
            $this->markTestSkipped('Este servidor no tiene la extensión zip.');
        }

        $this->seedRoles();
        $this->org = $this->organization();
        $this->cos = Service::create(['code' => 'COS', 'name' => 'Certificación anual', 'determines_fitness' => true, 'validity_days' => 365]);
        $this->coc = Service::create(['code' => 'COC', 'name' => 'Conformidad', 'determines_fitness' => true, 'validity_days' => 1825]);
    }

    /** Equipo de la organización, con su número de parte y tipo de equipo si se pide. */
    private function equipo(array $atributos = [], ?CatalogItem $tipo = null, ?Organization $org = null): Asset
    {
        $org ??= $this->org;

        return $this->asset($org, ['kind' => Asset::KIND_PART, 'catalog_item_id' => $tipo?->getKey(), ...$atributos]);
    }

    private function tipo(string $parte, ?int $diasDeTaller = null): CatalogItem
    {
        return CatalogItem::create([
            'kind' => Asset::KIND_PART,
            'description' => "Tipo {$parte}",
            'part_number' => $parte,
            'turnaround_days' => $diasDeTaller,
        ]);
    }

    /** Una certificación final de ese tipo: COS, COC… */
    private function certificar(Asset $activo, Service $tipo, InspectionResult $resultado, string $inspeccion, ?string $vence): void
    {
        $informe = $this->reportFor($activo, $resultado, $inspeccion, $vence);
        $informe->forceFill(['service_id' => $tipo->getKey()])->save();
        $informe->lines()->update(['service_id' => $tipo->getKey()]);

        app(RecalculateAssetStatus::class)($activo);
    }

    /**
     * Lee el .xlsx descargado: hojas por nombre, filas como lista de celdas.
     *
     * @return array<string, list<list<string>>>
     */
    private function hojas(string $descarga): array
    {
        $ruta = tempnam(sys_get_temp_dir(), 'export').'.xlsx';
        file_put_contents($ruta, $descarga);
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($ruta) === true, 'El archivo descargado no es un xlsx válido.');

        $compartidas = [];
        if (($xml = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
            foreach (simplexml_load_string($xml)->si as $si) {
                $texto = '';
                foreach ($si->xpath('.//*[local-name()="t"]') as $t) {
                    $texto .= (string) $t;
                }
                $compartidas[] = $texto;
            }
        }

        $nombres = [];
        foreach (simplexml_load_string((string) $zip->getFromName('xl/workbook.xml'))->sheets->sheet as $hoja) {
            $nombres[] = (string) $hoja['name'];
        }

        $resultado = [];
        foreach ($nombres as $i => $nombre) {
            $filas = [];
            $hoja = simplexml_load_string((string) $zip->getFromName('xl/worksheets/sheet'.($i + 1).'.xml'));
            foreach ($hoja->sheetData->row as $fila) {
                $celdas = [];
                foreach ($fila->c as $c) {
                    preg_match('/^([A-Z]+)/', (string) $c['r'], $m);
                    $col = 0;
                    foreach (str_split($m[1]) as $letra) {
                        $col = $col * 26 + (ord($letra) - 64);
                    }
                    $tipo = (string) $c['t'];
                    $celdas[$col - 1] = match ($tipo) {
                        's' => $compartidas[(int) $c->v] ?? '',
                        'inlineStr' => (string) $c->is->t,
                        default => (string) $c->v,
                    };
                }
                $filas[] = $celdas;
            }
            $resultado[$nombre] = $filas;
        }

        $zip->close();
        @unlink($ruta);

        return $resultado;
    }

    /** La fila de la hoja cuyo primer dato coincide, con las celdas por NOMBRE de columna. */
    private function fila(array $filas, string $columna, string $valor): array
    {
        $titulos = array_values($filas[0]);
        $indice = array_search($columna, $titulos, true);
        $this->assertNotFalse($indice, "Falta la columna «{$columna}». Hay: ".implode(' | ', $titulos));

        foreach (array_slice($filas, 1) as $fila) {
            if (($fila[$indice] ?? null) === $valor) {
                return array_combine($titulos, array_map(fn ($i) => $fila[$i] ?? '', array_keys($titulos)));
            }
        }

        $this->fail("No hay ninguna fila con {$columna} = {$valor}.");
    }

    private function descargarActivos(?User $usuario = null, string $url = '/activos/exportar'): array
    {
        $usuario ??= $this->staffUser(Permissions::ROLE_WORKSHOP);

        return $this->hojas($this->actingAs($usuario)->get($url)->streamedContent());
    }

    // --- Excel de activos ----------------------------------------------------------

    public function test_el_excel_de_activos_trae_numero_de_parte_y_las_fechas_de_cada_certificacion(): void
    {
        $activo = $this->equipo(['serial_number' => 'SN-A', 'code' => 'CODIGO-A'], $this->tipo('PN-123'));
        $this->certificar($activo, $this->cos, InspectionResult::Apto, '2026-06-01', '2027-06-01');
        $this->certificar($activo, $this->coc, InspectionResult::Apto, '2026-03-01', '2031-03-01');

        $fila = $this->fila($this->descargarActivos()['Hoja 1'] ?? array_values($this->descargarActivos())[0], 'Número de serie', 'SN-A');

        $this->assertSame('CODIGO-A', $fila['Código']);
        $this->assertSame('PN-123', $fila['Número de parte']);
        $this->assertSame('01/06/2026', $fila['COS · Fecha de inspección']);
        $this->assertSame('01/06/2027', $fila['COS · Vence']);
        $this->assertSame('Apto', $fila['COS · Resultado']);
        $this->assertSame('01/03/2026', $fila['COC · Fecha de inspección']);
        $this->assertSame('01/03/2031', $fila['COC · Vence']);
        // Con dos plazos manda el más próximo.
        $this->assertSame('01/06/2027', $fila['Vence']);
    }

    /** Lo que Daniel vio: un equipo no apto salía sin fecha aunque su COS siguiera vigente. */
    public function test_el_vencimiento_aparece_aunque_el_equipo_no_este_apto(): void
    {
        $activo = $this->equipo(['serial_number' => 'SN-NOAPTO']);
        $this->certificar($activo, $this->cos, InspectionResult::Apto, '2026-06-01', '2027-06-01');
        $this->certificar($activo, $this->coc, InspectionResult::NoApto, '2026-07-01', null);

        $this->assertSame('No apto', $activo->fresh()->status->label());
        $this->assertNull($activo->fresh()->expires_at, 'El estado guardado queda sin fecha: de ahí venía el hueco.');

        $hojas = $this->descargarActivos();
        $fila = $this->fila(array_values($hojas)[0], 'Número de serie', 'SN-NOAPTO');

        $this->assertSame('No apto', $fila['Estado']);
        $this->assertSame('01/06/2027', $fila['Vence']);
        $this->assertSame('No apto', $fila['COC · Resultado']);
        $this->assertSame('', $fila['COC · Vence']);
    }

    public function test_mandar_antes_usa_los_dias_de_taller_del_tipo_de_equipo(): void
    {
        $activo = $this->equipo(['serial_number' => 'SN-TALLER'], $this->tipo('PN-60', diasDeTaller: 60));
        $this->certificar($activo, $this->cos, InspectionResult::Apto, '2026-06-01', '2027-06-01');

        $margen = (int) config('odessa.recertification.safety_margin_days');
        $esperada = Carbon::parse('2027-06-01')->subDays(60 + $margen)->format('d/m/Y');

        $fila = $this->fila(array_values($this->descargarActivos())[0], 'Número de serie', 'SN-TALLER');

        $this->assertSame($esperada, $fila['Mandar antes de']);
    }

    public function test_un_equipo_sin_ninguna_inspeccion_sale_sin_fechas_pero_sale(): void
    {
        $this->equipo(['serial_number' => 'SN-VIRGEN']);

        $fila = $this->fila(array_values($this->descargarActivos())[0], 'Número de serie', 'SN-VIRGEN');

        $this->assertSame('', $fila['Vence']);
        $this->assertSame('', $fila['Mandar antes de']);
        $this->assertSame('', $fila['COS · Vence']);
    }

    public function test_un_cliente_baja_sus_vencimientos_y_nada_de_otra_empresa(): void
    {
        $propio = $this->equipo(['serial_number' => 'SN-PROPIO']);
        $this->certificar($propio, $this->cos, InspectionResult::Apto, '2026-06-01', '2027-06-01');

        $ajena = $this->organization();
        $ajeno = $this->equipo(['serial_number' => 'SN-AJENO'], null, $ajena);
        $this->certificar($ajeno, $this->cos, InspectionResult::Apto, '2026-06-01', '2027-09-09');

        $hojas = $this->descargarActivos($this->clientUser($this->org, Permissions::ROLE_CLIENT_VIEWER));
        $filas = array_values($hojas)[0];

        $this->assertSame('01/06/2027', $this->fila($filas, 'Número de serie', 'SN-PROPIO')['Vence']);
        $this->assertStringNotContainsString('SN-AJENO', json_encode($filas));
        $this->assertStringNotContainsString('09/09/2027', json_encode($filas));
    }

    // --- Reporte por organización -------------------------------------------------

    /** Antes: sólo certificados que vencían dentro de 90 días. Daniel pidió todos. */
    public function test_el_reporte_trae_todos_los_equipos_en_la_hoja_de_vencimientos(): void
    {
        $lejano = $this->equipo(['serial_number' => 'SN-LEJANO']);
        $this->certificar($lejano, $this->cos, InspectionResult::Apto, now()->subMonth()->toDateString(), now()->addDays(400)->toDateString());

        $proximo = $this->equipo(['serial_number' => 'SN-PROXIMO']);
        $this->certificar($proximo, $this->cos, InspectionResult::Apto, now()->subMonth()->toDateString(), now()->addDays(30)->toDateString());

        $rechazado = $this->equipo(['serial_number' => 'SN-RECHAZADO']);
        $this->certificar($rechazado, $this->cos, InspectionResult::NoApto, now()->subMonth()->toDateString(), null);

        $virgen = $this->equipo(['serial_number' => 'SN-VIRGEN']);

        $hojas = $this->hojas(
            $this->actingAs($this->staffUser(Permissions::ROLE_WORKSHOP))->get('/reportes/exportar')->streamedContent()
        );
        $vencimientos = $hojas['Vencimientos'];

        foreach (['SN-LEJANO', 'SN-PROXIMO', 'SN-RECHAZADO', 'SN-VIRGEN'] as $serie) {
            $this->assertSame($serie, $this->fila($vencimientos, 'Serie', $serie)['Serie'], "Falta {$serie}");
        }
    }

    public function test_la_hoja_de_vencimientos_dice_que_inspeccion_vence_y_cuando(): void
    {
        $activo = $this->equipo(['serial_number' => 'SN-DOS'], $this->tipo('PN-9'));
        $this->certificar($activo, $this->cos, InspectionResult::Apto, '2026-06-01', '2027-06-01');
        $this->certificar($activo, $this->coc, InspectionResult::Apto, '2026-03-01', '2031-03-01');

        $hojas = $this->hojas(
            $this->actingAs($this->staffUser(Permissions::ROLE_WORKSHOP))->get('/reportes/exportar')->streamedContent()
        );
        $fila = $this->fila($hojas['Vencimientos'], 'Serie', 'SN-DOS');

        $this->assertSame('PN-9', $fila['Número de parte']);
        $this->assertSame('01/06/2027', $fila['Vence']);
        $this->assertSame('COS', $fila['Inspección que vence primero']);
        $this->assertSame('01/06/2027', $fila['COS · Vence']);
        $this->assertSame('01/03/2031', $fila['COC · Vence']);
    }

    public function test_el_reporte_ordena_por_el_vencimiento_mas_proximo_y_deja_al_final_los_sin_fecha(): void
    {
        $tarde = $this->equipo(['serial_number' => 'SN-TARDE']);
        $this->certificar($tarde, $this->cos, InspectionResult::Apto, now()->subMonth()->toDateString(), now()->addDays(300)->toDateString());
        $this->equipo(['serial_number' => 'SN-SINFECHA']);
        $pronto = $this->equipo(['serial_number' => 'SN-PRONTO']);
        $this->certificar($pronto, $this->cos, InspectionResult::Apto, now()->subMonth()->toDateString(), now()->addDays(20)->toDateString());

        $hojas = $this->hojas(
            $this->actingAs($this->staffUser(Permissions::ROLE_WORKSHOP))->get('/reportes/exportar')->streamedContent()
        );
        $indice = array_search('Serie', $hojas['Vencimientos'][0], true);
        $series = array_map(fn ($f) => $f[$indice] ?? '', array_slice($hojas['Vencimientos'], 1));

        $this->assertSame(['SN-PRONTO', 'SN-TARDE', 'SN-SINFECHA'], $series);
    }

    public function test_un_cliente_baja_el_reporte_con_sus_vencimientos_y_solo_los_suyos(): void
    {
        $propio = $this->equipo(['serial_number' => 'SN-PROPIO']);
        $this->certificar($propio, $this->cos, InspectionResult::Apto, '2026-06-01', '2027-06-01');
        $ajeno = $this->equipo(['serial_number' => 'SN-AJENO'], null, $this->organization());
        $this->certificar($ajeno, $this->cos, InspectionResult::Apto, '2026-06-01', '2027-09-09');

        $hojas = $this->hojas(
            $this->actingAs($this->clientUser($this->org, Permissions::ROLE_CLIENT_VIEWER))->get('/reportes/exportar')->streamedContent()
        );

        $this->assertSame('01/06/2027', $this->fila($hojas['Vencimientos'], 'Serie', 'SN-PROPIO')['Vence']);
        $this->assertStringNotContainsString('SN-AJENO', json_encode($hojas['Vencimientos']));
    }
}
