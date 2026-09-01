<?php

namespace Tests\Unit;

use App\Actions\RecalculateAssetStatus;
use App\Enums\AssetStatus;
use App\Enums\InspectionResult;
use App\Enums\ReportStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La regla más importante del negocio: cómo se deriva la aptitud de una pieza.
 *
 * Se verifica exactamente lo que declara el sistema auditado: sólo la última
 * inspección con dictamen Apto/No Apto determina el estado.
 */
class AssetStatusTest extends TestCase
{
    use RefreshDatabase;

    private function recalculate(): RecalculateAssetStatus
    {
        return app(RecalculateAssetStatus::class);
    }

    public function test_una_pieza_sin_inspecciones_queda_en_estado_desconocido(): void
    {
        $asset = $this->asset($this->organization());

        $this->recalculate()($asset);

        $this->assertSame(AssetStatus::Unknown, $asset->fresh()->status);
        $this->assertNull($asset->fresh()->expires_at);
    }

    public function test_un_dictamen_apto_vigente_deja_la_pieza_apta(): void
    {
        $asset = $this->asset($this->organization());
        $this->reportFor($asset, InspectionResult::Apto, expiresAt: now()->addMonths(8)->toDateString());

        $this->recalculate()($asset);

        $this->assertSame(AssetStatus::Fit, $asset->fresh()->status);
    }

    public function test_un_dictamen_apto_dentro_de_la_ventana_preventiva_marca_proximo_a_vencer(): void
    {
        config()->set('odessa.expiry_warning_days', 60);

        $asset = $this->asset($this->organization());
        $this->reportFor($asset, InspectionResult::Apto, expiresAt: now()->addDays(30)->toDateString());

        $this->recalculate()($asset);

        $this->assertSame(AssetStatus::Expiring, $asset->fresh()->status);
    }

    public function test_un_dictamen_apto_ya_vencido_pasa_a_no_apto(): void
    {
        $asset = $this->asset($this->organization());
        $this->reportFor($asset, InspectionResult::Apto, expiresAt: now()->subDay()->toDateString());

        $this->recalculate()($asset);

        $this->assertSame(AssetStatus::Unfit, $asset->fresh()->status);
    }

    public function test_un_dictamen_no_apto_manda_sin_importar_el_vencimiento(): void
    {
        $asset = $this->asset($this->organization());
        $this->reportFor($asset, InspectionResult::NoApto, expiresAt: now()->addYear()->toDateString());

        $this->recalculate()($asset);

        $this->assertSame(AssetStatus::Unfit, $asset->fresh()->status);
    }

    /**
     * Este es el caso que más se malinterpreta: una intervención posterior
     * "No aplica" NO invalida ni renueva el dictamen vigente.
     */
    public function test_las_inspecciones_no_aplica_no_alteran_el_estado(): void
    {
        $asset = $this->asset($this->organization());

        $determinante = $this->reportFor(
            $asset,
            InspectionResult::Apto,
            inspectedAt: now()->subMonths(6)->toDateString(),
            expiresAt: now()->addMonths(6)->toDateString(),
        );

        // Intervención operativa más reciente, sin dictamen.
        $this->reportFor($asset, InspectionResult::NoAplica, inspectedAt: now()->subDay()->toDateString());

        $this->recalculate()($asset);

        $fresh = $asset->fresh();
        $this->assertSame(AssetStatus::Fit, $fresh->status);
        $this->assertSame($determinante->getKey(), $fresh->current_inspection_report_id);
    }

    public function test_las_inspecciones_sin_procesar_tampoco_determinan_el_estado(): void
    {
        $asset = $this->asset($this->organization());
        $this->reportFor($asset, InspectionResult::SinProcesar, expiresAt: now()->addYear()->toDateString());

        $this->recalculate()($asset);

        $this->assertSame(AssetStatus::Unknown, $asset->fresh()->status);
    }

    public function test_un_fmr_en_borrador_no_propaga_su_dictamen(): void
    {
        $asset = $this->asset($this->organization());
        $this->reportFor(
            $asset,
            InspectionResult::Apto,
            expiresAt: now()->addYear()->toDateString(),
            status: ReportStatus::Draft,
        );

        $this->recalculate()($asset);

        $this->assertSame(AssetStatus::Unknown, $asset->fresh()->status);
    }

    public function test_manda_la_inspeccion_mas_reciente_entre_varios_dictamenes(): void
    {
        $asset = $this->asset($this->organization());

        $this->reportFor(
            $asset,
            InspectionResult::NoApto,
            inspectedAt: now()->subYear()->toDateString(),
        );

        $reciente = $this->reportFor(
            $asset,
            InspectionResult::Apto,
            inspectedAt: now()->subMonth()->toDateString(),
            expiresAt: now()->addMonths(11)->toDateString(),
        );

        $this->recalculate()($asset);

        $fresh = $asset->fresh();
        $this->assertSame(AssetStatus::Fit, $fresh->status);
        $this->assertSame($reciente->getKey(), $fresh->current_inspection_report_id);
    }

    public function test_el_recalculo_es_idempotente(): void
    {
        $asset = $this->asset($this->organization());
        $this->reportFor($asset, InspectionResult::Apto, expiresAt: now()->addMonths(8)->toDateString());

        $this->recalculate()($asset);
        $primera = $asset->fresh()->only(['status', 'expires_at', 'current_inspection_report_id']);

        $this->recalculate()($asset->fresh());
        $segunda = $asset->fresh()->only(['status', 'expires_at', 'current_inspection_report_id']);

        $this->assertEquals($primera, $segunda);
    }
}
