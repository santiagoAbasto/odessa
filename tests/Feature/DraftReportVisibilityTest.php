<?php

namespace Tests\Feature;

use App\Enums\InspectionResult;
use App\Enums\ReportStatus;
use App\Models\Asset;
use App\Models\InspectionReport;
use App\Models\Organization;
use App\Models\User;
use App\Support\AssetTimeline;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Un borrador es una declaración en curso del taller: el cliente no lo ve.
 *
 * Pedido de Daniel (04/11/2026): al crear el informe «004» el operador del
 * cliente ya lo veía en su listado. Se cubre cada vía por la que un borrador
 * podría llegar a un cliente, porque tapar sólo el listado dejaba abiertas la
 * ficha del equipo, su hilo, el informe suelto y los archivos adjuntos.
 */
class DraftReportVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Asset $activo;

    private InspectionReport $borrador;

    private InspectionReport $final;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        Storage::fake(config('odessa.documents_disk'));

        $this->org = $this->organization();
        $this->activo = $this->asset($this->org);
        $vence = now()->addYear()->toDateString();

        $this->final = $this->reportFor($this->activo, InspectionResult::Apto, now()->subMonth()->toDateString(), $vence);
        $this->borrador = $this->reportFor($this->activo, InspectionResult::Apto, now()->toDateString(), $vence, ReportStatus::Draft);
    }

    private function cliente(): User
    {
        return $this->clientUser($this->org, Permissions::ROLE_CLIENT_OPERATOR);
    }

    private function idsDelListado(string $url = '/informes'): array
    {
        return collect($this->get($url)->viewData('page')['props']['reports']['data'])->pluck('id')->all();
    }

    public function test_el_cliente_no_ve_el_borrador_en_su_listado_de_informes(): void
    {
        $this->actingAs($this->cliente());

        $ids = $this->idsDelListado();

        $this->assertContains($this->final->public_id, $ids);
        $this->assertNotContains($this->borrador->public_id, $ids);
    }

    public function test_el_taller_sigue_viendo_el_borrador_en_el_listado(): void
    {
        $this->actingAs($this->staffUser(Permissions::ROLE_WORKSHOP));

        $this->assertContains($this->borrador->public_id, $this->idsDelListado());
    }

    public function test_el_cliente_no_puede_abrir_un_borrador_ni_conociendo_su_id(): void
    {
        $this->actingAs($this->cliente());

        $this->get("/informes/{$this->borrador->public_id}")->assertNotFound();
        $this->get("/informes/{$this->final->public_id}")->assertOk();
    }

    public function test_el_taller_puede_abrir_el_borrador(): void
    {
        $this->actingAs($this->staffUser(Permissions::ROLE_WORKSHOP))
            ->get("/informes/{$this->borrador->public_id}")
            ->assertOk();
    }

    public function test_la_ficha_del_equipo_no_muestra_al_cliente_las_lineas_de_un_borrador(): void
    {
        $this->actingAs($this->cliente());

        $historial = $this->get("/activos/{$this->activo->public_id}")->viewData('page')['props']['history'];

        $this->assertCount(1, $historial);
        $this->assertSame($this->final->public_id, $historial[0]['report']['id']);
    }

    public function test_la_ficha_del_equipo_le_muestra_al_taller_el_borrador_y_el_final(): void
    {
        $this->actingAs($this->staffUser(Permissions::ROLE_WORKSHOP));

        $historial = $this->get("/activos/{$this->activo->public_id}")->viewData('page')['props']['history'];

        $this->assertCount(2, $historial);
    }

    public function test_el_hilo_de_trazabilidad_no_le_muestra_al_cliente_el_borrador(): void
    {
        $this->actingAs($this->cliente());

        $hilo = collect((new AssetTimeline($this->activo))->build())
            ->where('kind', 'inspection');

        $this->assertCount(1, $hilo);
        $this->assertStringContainsString($this->final->number, $hilo->first()['detail']);
    }

    /** Cubre el panel y cualquier consulta directa: el recorte vive en el modelo. */
    public function test_ninguna_consulta_de_un_cliente_devuelve_el_borrador(): void
    {
        $this->actingAs($this->cliente());

        $this->assertFalse(InspectionReport::query()->whereKey($this->borrador->getKey())->exists());
        $this->assertTrue(InspectionReport::query()->whereKey($this->final->getKey())->exists());
    }

    public function test_el_cliente_no_descarga_un_archivo_adjunto_a_un_borrador(): void
    {
        $delBorrador = $this->document($this->borrador);
        $delFinal = $this->document($this->final);
        $this->actingAs($this->cliente());

        $this->get("/documentos/{$delBorrador->public_id}/descargar")->assertForbidden();
        $this->get("/documentos/{$delFinal->public_id}/descargar")->assertOk();
    }

    public function test_el_taller_descarga_el_archivo_de_un_borrador(): void
    {
        $delBorrador = $this->document($this->borrador);

        $this->actingAs($this->staffUser(Permissions::ROLE_WORKSHOP))
            ->get("/documentos/{$delBorrador->public_id}/descargar")
            ->assertOk();
    }
}
