<?php

namespace Tests\Feature;

use App\Enums\AssetStatus;
use App\Models\Asset;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ventanas de vencimiento y alcance de la exportación.
 *
 * El negocio pidió "mejor filtración de próximos a vencer": las ventanas
 * listas evitan calcular la fecha a mano cada vez, que es lo que se hacía.
 */
class AssetExpiryFiltersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    private function conVencimiento(int $dias, ?int $enviarEn = null): Asset
    {
        $activo = $this->asset($this->organization());

        $activo->forceFill([
            'status' => AssetStatus::Fit,
            'expires_at' => today()->addDays($dias),
            'send_by_at' => $enviarEn === null ? null : today()->addDays($enviarEn),
        ])->save();

        return $activo;
    }

    /** @return list<string> */
    private function seriesVisibles(array $query): array
    {
        $respuesta = $this->actingAs($this->staffUser(Permissions::ROLE_WORKSHOP))
            ->get('/activos?'.http_build_query($query))
            ->assertOk();

        return collect($respuesta->viewData('page')['props']['assets']['data'])
            ->pluck('serialNumber')
            ->all();
    }

    public function test_la_ventana_de_vencimiento_acota_por_dias(): void
    {
        $pronto = $this->conVencimiento(20);
        $lejos = $this->conVencimiento(200);

        $series = $this->seriesVisibles(['due_in' => '30']);

        $this->assertContains($pronto->serial_number, $series);
        $this->assertNotContains($lejos->serial_number, $series);
    }

    /** Lo ya vencido no es "próximo a vencer": tiene su propio filtro. */
    public function test_la_ventana_no_incluye_lo_ya_vencido(): void
    {
        $vencido = $this->conVencimiento(-10);

        $this->assertNotContains($vencido->serial_number, $this->seriesVisibles(['due_in' => '30']));
        $this->assertContains($vencido->serial_number, $this->seriesVisibles(['overdue' => '1']));
    }

    /**
     * La fecha límite de envío es la que de verdad limita al cliente: un
     * equipo puede estar lejos de vencer y ya sin tiempo para mandarlo.
     */
    public function test_la_fecha_limite_de_envio_filtra_aparte_del_vencimiento(): void
    {
        $sinTiempo = $this->conVencimiento(120, enviarEn: 10);
        $conTiempo = $this->conVencimiento(120, enviarEn: 90);

        $series = $this->seriesVisibles(['send_by_in' => '15']);

        $this->assertContains($sinTiempo->serial_number, $series);
        $this->assertNotContains($conTiempo->serial_number, $series);
        // Y no aparece en la ventana de vencimiento: todavía falta mucho.
        $this->assertNotContains($sinTiempo->serial_number, $this->seriesVisibles(['due_in' => '30']));
    }

    /**
     * Regresión: el listado y la exportación admitían filtros distintos, así
     * que filtrar por cliente y después descargar traía el inventario de
     * todas las empresas.
     */
    public function test_la_exportacion_respeta_el_filtro_por_cliente(): void
    {
        $unaOrg = $this->organization('UNO');
        $otraOrg = $this->organization('DOS');
        $mia = $this->asset($unaOrg);
        $ajena = $this->asset($otraOrg);

        $respuesta = $this->actingAs($this->staffUser(Permissions::ROLE_WORKSHOP))
            ->get('/activos/exportar?'.http_build_query(['organization' => $unaOrg->public_id]))
            ->assertOk();

        $contenido = $this->textoExportado($respuesta->streamedContent());

        $this->assertStringContainsString($mia->serial_number, $contenido);
        $this->assertStringNotContainsString($ajena->serial_number, $contenido);
    }

    /**
     * Texto plano de lo exportado.
     *
     * Según tenga o no ext-zip, el servidor devuelve un .xlsx —que es un zip—
     * o un .csv. La prueba no debe depender de cuál: se queda con el texto.
     */
    private function textoExportado(string $contenido): string
    {
        if (! str_starts_with($contenido, "PK\x03\x04")) {
            return $contenido;
        }

        $ruta = tempnam(sys_get_temp_dir(), 'export').'.xlsx';
        file_put_contents($ruta, $contenido);

        $zip = new \ZipArchive;
        $zip->open($ruta);

        // Se concatena todo el paquete en vez de apuntar a una entrada: según
        // cómo escriba la librería, los valores pueden ir en la tabla de
        // cadenas compartidas o embebidos en la hoja.
        $texto = '';
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $texto .= (string) $zip->getFromIndex($i);
        }

        $zip->close();
        unlink($ruta);

        return $texto;
    }
}
