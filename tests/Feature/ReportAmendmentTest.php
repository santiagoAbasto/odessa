<?php

namespace Tests\Feature;

use App\Actions\RecalculateAssetStatus;
use App\Enums\AssetStatus;
use App\Enums\InspectionResult;
use App\Enums\ReportStatus;
use App\Models\ActivityLog;
use App\Models\Asset;
use App\Models\InspectionReport;
use App\Models\Organization;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Corregir o dar de baja un informe, incluso ya final.
 *
 * Pedido de Daniel (04/11/2026): «tanto taller como superadmin poder editar
 * informe, o eliminar, en caso de errores, y que quede en auditoría». Un
 * informe final decide el estado de las piezas, así que cada corrección tiene
 * que recalcularlas y dejar el antes y el después registrados.
 */
class ReportAmendmentTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Asset $activo;

    private InspectionReport $informe;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();

        $this->org = $this->organization();
        $this->activo = $this->asset($this->org);
        $this->informe = $this->reportFor($this->activo, InspectionResult::Apto, now()->subMonth()->toDateString(), now()->addYear()->toDateString());
        $this->informe->forceFill(['number' => '004', 'finalized_at' => now()])->save();
        app(RecalculateAssetStatus::class)($this->activo);
    }

    private function taller(): User
    {
        return $this->staffUser(Permissions::ROLE_WORKSHOP);
    }

    // --- Quién puede ---------------------------------------------------------

    public function test_taller_y_superadmin_pueden_editar_un_informe_ya_final(): void
    {
        foreach ([Permissions::ROLE_WORKSHOP, Permissions::ROLE_SUPER_ADMIN] as $rol) {
            $this->actingAs($this->staffUser($rol))
                ->put("/informes/{$this->informe->public_id}", ['number' => "004-{$rol}", 'inspected_at' => now()->subMonth()->toDateString()])
                ->assertSessionHasNoErrors();

            $this->assertSame("004-{$rol}", $this->informe->fresh()->number);
        }
    }

    public function test_un_cliente_no_puede_editar_ni_eliminar_un_informe(): void
    {
        $cliente = $this->clientUser($this->org, Permissions::ROLE_CLIENT_OPERATOR);

        $this->actingAs($cliente)
            ->put("/informes/{$this->informe->public_id}", ['number' => 'HACK', 'inspected_at' => now()->toDateString()])
            ->assertForbidden();
        $this->actingAs($cliente)
            ->delete("/informes/{$this->informe->public_id}", ['reason' => 'No me gusta'])
            ->assertForbidden();

        $this->assertSame('004', $this->informe->fresh()->number);
        $this->assertNull($this->informe->fresh()->deleted_at);
    }

    // --- Editar ------------------------------------------------------------------

    public function test_editar_los_datos_deja_el_antes_y_el_despues_en_auditoria(): void
    {
        $this->actingAs($this->taller())
            ->put("/informes/{$this->informe->public_id}", [
                'number' => '005',
                'inspected_at' => now()->subMonth()->toDateString(),
                'notes' => 'Corregido el número.',
            ])->assertSessionHasNoErrors();

        $log = ActivityLog::where('action', 'report.updated')->firstOrFail();
        $this->assertSame('004', $log->changes['old']['number']);
        $this->assertSame('005', $log->changes['new']['number']);
        $this->assertSame($this->informe->getKey(), $log->subject_id);
    }

    public function test_no_se_puede_repetir_el_numero_de_otro_informe_de_la_empresa(): void
    {
        $otro = $this->reportFor($this->asset($this->org));
        $otro->forceFill(['number' => '777'])->save();

        $this->actingAs($this->taller())
            ->put("/informes/{$this->informe->public_id}", ['number' => '777', 'inspected_at' => now()->subMonth()->toDateString()])
            ->assertSessionHasErrors('number');

        $this->assertSame('004', $this->informe->fresh()->number);
    }

    public function test_corregir_el_dictamen_de_un_informe_final_recalcula_la_pieza(): void
    {
        $this->assertSame(AssetStatus::Fit, $this->activo->fresh()->status);
        $linea = $this->informe->lines()->firstOrFail();

        $this->actingAs($this->taller())
            ->post("/informes/{$this->informe->public_id}/dictamen", [
                'line_ids' => [$linea->id],
                'result' => InspectionResult::NoApto->value,
                'findings' => 'Se cargó apto por error.',
            ])->assertSessionHasNoErrors();

        $this->assertSame(AssetStatus::Unfit, $this->activo->fresh()->status);

        $log = ActivityLog::where('action', 'report.lines_resolved')->latest('id')->firstOrFail();
        $this->assertNotEmpty($log->changes['old']);
    }

    public function test_corregir_a_apto_sin_vencimiento_calcula_el_de_la_operacion(): void
    {
        $linea = $this->informe->lines()->firstOrFail();
        $linea->forceFill(['result' => InspectionResult::NoApto->value, 'expires_at' => null])->save();
        app(RecalculateAssetStatus::class)($this->activo);
        $this->assertSame(AssetStatus::Unfit, $this->activo->fresh()->status);

        $this->actingAs($this->taller())
            ->post("/informes/{$this->informe->public_id}/dictamen", [
                'line_ids' => [$linea->id],
                'result' => InspectionResult::Apto->value,
            ])->assertSessionHasNoErrors();

        $activo = $this->activo->fresh();
        $this->assertSame(AssetStatus::Fit, $activo->status);
        $this->assertNotNull($activo->expires_at, 'Un apto no puede quedar sin vencimiento.');
    }

    // --- Eliminar ----------------------------------------------------------------

    public function test_eliminar_exige_un_motivo(): void
    {
        $this->actingAs($this->taller())
            ->delete("/informes/{$this->informe->public_id}", [])
            ->assertSessionHasErrors('reason');

        $this->assertNull($this->informe->fresh()->deleted_at);
    }

    public function test_eliminar_un_informe_final_recalcula_las_piezas_y_queda_auditado(): void
    {
        $this->assertSame(AssetStatus::Fit, $this->activo->fresh()->status);

        $this->actingAs($this->taller())
            ->delete("/informes/{$this->informe->public_id}", ['reason' => 'Se cargó sobre el equipo equivocado.'])
            ->assertRedirect('/informes');

        $this->assertSoftDeleted($this->informe);
        // Sin ningún informe vigente la pieza vuelve a «sin dictaminar».
        $this->assertSame(AssetStatus::Unknown, $this->activo->fresh()->status);

        $log = ActivityLog::where('action', 'report.deleted')->firstOrFail();
        $this->assertSame('004', $log->changes['old']['number']);
        $this->assertStringContainsString('equipo equivocado', $log->description);
    }

    public function test_eliminar_libera_el_numero_para_cargar_el_correcto(): void
    {
        $this->actingAs($this->taller())
            ->delete("/informes/{$this->informe->public_id}", ['reason' => 'Número mal cargado.'])
            ->assertRedirect();

        $this->post('/informes', [
            'organization_public_id' => $this->org->public_id,
            'number' => '004',
            'inspected_at' => now()->toDateString(),
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame(1, InspectionReport::query()->where('number', '004')->count());
    }

    // --- Historial en el propio informe -----------------------------------------

    public function test_el_taller_ve_el_historial_de_cambios_del_informe_y_el_cliente_no(): void
    {
        $this->actingAs($this->taller())
            ->put("/informes/{$this->informe->public_id}", ['number' => '005', 'inspected_at' => now()->subMonth()->toDateString()]);

        $props = $this->get("/informes/{$this->informe->public_id}")->viewData('page')['props'];
        $this->assertNotEmpty($props['history']);
        $this->assertTrue($props['can']['update']);
        $this->assertTrue($props['can']['delete']);

        auth()->logout();
        $propsCliente = $this->actingAs($this->clientUser($this->org, Permissions::ROLE_CLIENT_OPERATOR))
            ->get("/informes/{$this->informe->public_id}")->viewData('page')['props'];
        $this->assertNull($propsCliente['history']);
        $this->assertFalse($propsCliente['can']['delete']);
    }

    /**
     * Invariante, no permiso: el superadministrador atraviesa las Policies con
     * Gate::before, así que «anulado es inmutable» no puede vivir sólo ahí.
     */
    public function test_un_informe_anulado_no_se_edita_ni_siquiera_el_superadmin(): void
    {
        $this->informe->forceFill(['status' => ReportStatus::Cancelled])->save();
        $linea = $this->informe->lines()->firstOrFail();

        foreach ([Permissions::ROLE_WORKSHOP, Permissions::ROLE_SUPER_ADMIN] as $rol) {
            $this->actingAs($this->staffUser($rol));

            $this->put("/informes/{$this->informe->public_id}", ['number' => '009', 'inspected_at' => now()->subMonth()->toDateString()])
                ->assertForbidden();
            $this->post("/informes/{$this->informe->public_id}/dictamen", ['line_ids' => [$linea->id], 'result' => 'no_apto'])
                ->assertForbidden();
        }

        $this->assertSame('004', $this->informe->fresh()->number);
        $this->assertSame(InspectionResult::Apto, $linea->fresh()->result);
    }

    public function test_la_ficha_de_un_informe_final_no_ofrece_finalizar_otra_vez(): void
    {
        $props = $this->actingAs($this->staffUser(Permissions::ROLE_SUPER_ADMIN))
            ->get("/informes/{$this->informe->public_id}")->viewData('page')['props'];

        $this->assertFalse($props['can']['finalize']);
        $this->assertTrue($props['can']['update']);
    }
}
