<?php

namespace Tests\Feature;

use App\Actions\RecalculateAssetStatus;
use App\Enums\AssetStatus;
use App\Models\ActivityLog;
use App\Models\Asset;
use App\Models\AssetExternalCertification;
use App\Models\InspectionReport;
use App\Models\Organization;
use App\Models\Service;
use App\Models\User;
use App\Support\AssetCertificationSummary;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Certificación que el equipo ya traía de otra empresa, cargada a mano en la
 * ficha (pedido del 05/11/2026): no genera un informe, cuenta para el estado y
 * los vencimientos, y queda en la auditoría.
 */
class CertificacionExternaTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organizacion;

    private Service $cos;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();

        config(['odessa.recertification.turnaround_days' => 30, 'odessa.recertification.safety_margin_days' => 15]);

        $this->organizacion = $this->organization();
        $this->cos = Service::create(['code' => 'COS', 'name' => 'Certificación anual', 'determines_fitness' => true, 'validity_days' => 365, 'turnaround_days' => 20]);
        $this->organizacion->operations()->attach($this->cos->getKey());
    }

    private function operador(): User
    {
        return $this->clientUser($this->organizacion, Permissions::ROLE_CLIENT_OPERATOR);
    }

    private function dia(int $enDias): string
    {
        return now()->addDays($enDias)->toDateString();
    }

    private function datos(array $extra = []): array
    {
        return [
            'service_id' => $this->cos->getKey(),
            'certified_by' => 'Certificadora SA',
            'certificate_number' => 'CERT-123',
            'certified_at' => $this->dia(-30),
            'expires_at' => $this->dia(335),
            ...$extra,
        ];
    }

    private function cargar(Asset $activo, array $extra = [], ?User $usuario = null)
    {
        return $this->actingAs($usuario ?? $this->operador())
            ->post("/activos/{$activo->public_id}/certificaciones-externas", $this->datos($extra));
    }

    public function test_el_operador_la_carga_sin_generar_un_informe_y_cuenta_para_el_estado(): void
    {
        $activo = $this->asset($this->organizacion);

        $this->cargar($activo)->assertSessionHasNoErrors();

        $activo->refresh();

        $this->assertSame(0, InspectionReport::withoutGlobalScope('organization')->count());
        $this->assertSame(AssetStatus::Fit, $activo->status);
        $this->assertSame($this->dia(335), $activo->expires_at->toDateString());
        // 335 − 20 (días de COS) − 15 (margen) = día 300.
        $this->assertSame($this->dia(300), $activo->send_by_at->toDateString());
        $this->assertNull($activo->current_inspection_report_id);
    }

    public function test_queda_en_la_auditoria_como_carga_manual(): void
    {
        $activo = $this->asset($this->organizacion);

        $this->cargar($activo);

        $log = ActivityLog::query()->where('action', 'asset.external_certification_saved')->firstOrFail();

        $this->assertSame($activo->getKey(), $log->subject_id);
        $this->assertStringContainsString('No es un informe de Odessa', $log->description);
        $this->assertSame('Certificadora SA', $log->changes['new']['certified_by']);
    }

    public function test_cargarla_otra_vez_la_edita_y_guarda_el_antes_y_el_despues(): void
    {
        $activo = $this->asset($this->organizacion);
        $this->cargar($activo);

        $this->cargar($activo, ['expires_at' => $this->dia(200), 'certificate_number' => 'CERT-999'])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, AssetExternalCertification::query()->where('asset_id', $activo->getKey())->count());
        $this->assertSame($this->dia(200), $activo->fresh()->expires_at->toDateString());

        $log = ActivityLog::query()->where('action', 'asset.external_certification_saved')->latest('id')->firstOrFail();
        $this->assertSame($this->dia(335), $log->changes['old']['expires_at']);
        $this->assertSame($this->dia(200), $log->changes['new']['expires_at']);
    }

    public function test_una_certificacion_vencida_deja_el_equipo_no_apto(): void
    {
        $activo = $this->asset($this->organizacion);

        $this->cargar($activo, ['certified_at' => $this->dia(-400), 'expires_at' => $this->dia(-5)])
            ->assertSessionHasNoErrors();

        $this->assertSame(AssetStatus::Unfit, $activo->fresh()->status);
    }

    public function test_quitarla_deja_el_equipo_sin_dictamen_y_queda_en_la_auditoria(): void
    {
        $activo = $this->asset($this->organizacion);
        $this->cargar($activo);
        $certificacion = AssetExternalCertification::query()->firstOrFail();

        $this->actingAs($this->operador())
            ->delete("/activos/{$activo->public_id}/certificaciones-externas/{$certificacion->getKey()}")
            ->assertRedirect();

        $this->assertSame(0, AssetExternalCertification::query()->count());
        $this->assertSame(AssetStatus::Unknown, $activo->fresh()->status);
        $this->assertTrue(ActivityLog::query()->where('action', 'asset.external_certification_removed')->exists());
    }

    public function test_si_odessa_ya_certifico_esa_operacion_no_se_carga_a_mano(): void
    {
        $activo = $this->asset($this->organizacion);
        $informe = $this->reportFor($activo, expiresAt: $this->dia(100));
        $informe->forceFill(['service_id' => $this->cos->getKey()])->save();
        $informe->lines()->update(['service_id' => $this->cos->getKey()]);

        $this->cargar($activo)->assertSessionHasErrors('service_id');

        $this->assertSame(0, AssetExternalCertification::query()->count());
    }

    public function test_el_informe_de_odessa_posterior_manda_sobre_la_cargada_a_mano(): void
    {
        $activo = $this->asset($this->organizacion);
        $this->cargar($activo);

        // Después Odessa certifica la misma operación: rige su informe.
        $informe = $this->reportFor($activo, expiresAt: $this->dia(100));
        $informe->forceFill(['service_id' => $this->cos->getKey()])->save();
        $informe->lines()->update(['service_id' => $this->cos->getKey()]);
        app(RecalculateAssetStatus::class)($activo);

        $this->assertSame($this->dia(100), $activo->fresh()->expires_at->toDateString());

        $cert = (new AssetCertificationSummary)->forAssets([$activo->fresh()])[$activo->getKey()]['certifications'][$this->cos->getKey()];
        $this->assertFalse($cert['external']);
    }

    public function test_solo_operaciones_que_certifican_y_habilitadas_para_la_empresa(): void
    {
        $activo = $this->asset($this->organizacion);
        $noHabilitada = Service::create(['code' => 'COC', 'name' => 'Conformidad', 'determines_fitness' => true, 'validity_days' => 1825]);
        $noCertifica = Service::create(['code' => 'OPE', 'name' => 'Operacional', 'determines_fitness' => false, 'validity_days' => 365]);
        $this->organizacion->operations()->attach($noCertifica->getKey());

        $this->cargar($activo, ['service_id' => $noHabilitada->getKey()])->assertSessionHasErrors('service_id');
        $this->cargar($activo, ['service_id' => $noCertifica->getKey()])->assertSessionHasErrors('service_id');

        $this->assertSame(0, AssetExternalCertification::query()->count());
    }

    public function test_las_fechas_tienen_que_ser_coherentes(): void
    {
        $activo = $this->asset($this->organizacion);

        $this->cargar($activo, ['certified_at' => $this->dia(5)])->assertSessionHasErrors('certified_at');
        $this->cargar($activo, ['expires_at' => $this->dia(-30)])->assertSessionHasErrors('expires_at');
        $this->cargar($activo, ['certified_by' => ''])->assertSessionHasErrors('certified_by');
    }

    public function test_el_taller_y_el_cliente_de_consulta_no_pueden_cargarla(): void
    {
        $activo = $this->asset($this->organizacion);

        $this->cargar($activo, usuario: $this->staffUser(Permissions::ROLE_WORKSHOP))->assertForbidden();
        $this->cargar($activo, usuario: $this->clientUser($this->organizacion, Permissions::ROLE_CLIENT_VIEWER))->assertForbidden();

        $this->assertSame(0, AssetExternalCertification::query()->count());
    }

    public function test_un_operador_no_puede_cargarla_en_un_equipo_de_otra_empresa(): void
    {
        $ajeno = $this->asset($this->organization());

        $this->cargar($ajeno)->assertNotFound();

        $this->assertSame(0, AssetExternalCertification::query()->count());
    }

    public function test_el_superadmin_tambien_puede(): void
    {
        $activo = $this->asset($this->organizacion);

        $this->cargar($activo, usuario: $this->staffUser(Permissions::ROLE_SUPER_ADMIN))->assertSessionHasNoErrors();

        $this->assertSame(1, AssetExternalCertification::query()->count());
    }

    public function test_la_ficha_ofrece_las_operaciones_sin_certificacion_solo_a_quien_puede_cargarla(): void
    {
        $activo = $this->asset($this->organizacion);

        $props = fn ($usuario) => $this->actingAs($usuario)->get("/activos/{$activo->public_id}")->viewData('page')['props'];

        $operador = $props($this->operador());
        $this->assertTrue($operador['can']['externalCertification']);
        $this->assertSame(['COS'], collect($operador['externalServices'])->pluck('code')->all());

        $taller = $props($this->staffUser(Permissions::ROLE_WORKSHOP));
        $this->assertFalse($taller['can']['externalCertification']);
        $this->assertSame([], $taller['externalServices']);

        // Ya cargada: deja de ofrecerse y aparece entre las certificaciones, marcada.
        $this->cargar($activo);
        $conCarga = $props($this->operador());
        $this->assertSame([], $conCarga['externalServices']);
        $this->assertTrue($conCarga['certifications'][0]['external']);
        $this->assertSame('Certificadora SA', $conCarga['certifications'][0]['certifiedBy']);
    }

    public function test_la_planilla_aclara_que_es_de_otra_empresa(): void
    {
        $activo = $this->asset($this->organizacion);
        $this->cargar($activo);

        $resumen = new AssetCertificationSummary;
        $celdas = $resumen->cells($resumen->forAssets([$activo->fresh()])[$activo->getKey()]['certifications']);

        $this->assertContains('Apto (otra empresa)', $celdas);
    }
}
