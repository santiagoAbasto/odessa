<?php

namespace Tests\Feature;

use App\Actions\FinalizeInspectionReport;
use App\Enums\AssetStatus;
use App\Enums\InspectionResult;
use App\Enums\ReportStatus;
use App\Models\InspectionReport;
use App\Models\InspectionReportAsset;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Cierre de un FMR y su efecto sobre las piezas. */
class InspectionWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    public function test_finalizar_un_informe_calcula_el_vencimiento_y_actualiza_la_pieza(): void
    {
        $organizacion = $this->organization();
        $activo = $this->asset($organizacion);
        $servicio = $this->service(determinesFitness: true, validityDays: 365);

        $informe = InspectionReport::forceCreate([
            'public_id' => (string) Str::uuid7(),
            'organization_id' => $organizacion->getKey(),
            'number' => '5001',
            'inspected_at' => now()->subDays(10)->toDateString(),
            'status' => ReportStatus::Draft->value,
            'service_id' => $servicio->getKey(),
        ]);

        InspectionReportAsset::create([
            'inspection_report_id' => $informe->getKey(),
            'asset_id' => $activo->getKey(),
            'service_id' => $servicio->getKey(),
            'result' => InspectionResult::Apto->value,
            // Sin fecha: debe calcularla la acción.
            'expires_at' => null,
        ]);

        $this->actingAs($this->staffUser(Permissions::ROLE_WORKSHOP));

        app(FinalizeInspectionReport::class)($informe);

        $linea = InspectionReportAsset::where('inspection_report_id', $informe->getKey())->first();

        $this->assertSame(
            now()->subDays(10)->addDays(365)->toDateString(),
            $linea->expires_at->toDateString(),
        );

        $this->assertSame(ReportStatus::Final, $informe->fresh()->status);
        $this->assertSame(AssetStatus::Fit, $activo->fresh()->status);
        $this->assertSame($informe->getKey(), $activo->fresh()->current_inspection_report_id);
    }

    public function test_finalizar_respeta_un_vencimiento_fijado_a_mano(): void
    {
        $organizacion = $this->organization();
        $activo = $this->asset($organizacion);
        $fecha = now()->addDays(90)->toDateString();

        $informe = $this->reportFor(
            $activo,
            InspectionResult::Apto,
            expiresAt: $fecha,
            status: ReportStatus::Draft,
        );

        $this->actingAs($this->staffUser(Permissions::ROLE_WORKSHOP));
        app(FinalizeInspectionReport::class)($informe);

        $this->assertSame($fecha, $activo->fresh()->expires_at->toDateString());
    }

    public function test_no_se_puede_finalizar_un_informe_sin_piezas(): void
    {
        $organizacion = $this->organization();

        $informe = InspectionReport::forceCreate([
            'public_id' => (string) Str::uuid7(),
            'organization_id' => $organizacion->getKey(),
            'number' => '5002',
            'inspected_at' => now()->toDateString(),
            'status' => ReportStatus::Draft->value,
        ]);

        $this->actingAs($this->staffUser(Permissions::ROLE_WORKSHOP));

        $this->expectException(ValidationException::class);
        app(FinalizeInspectionReport::class)($informe);
    }

    public function test_no_se_puede_finalizar_dos_veces_el_mismo_informe(): void
    {
        $organizacion = $this->organization();
        $informe = $this->reportFor($this->asset($organizacion), status: ReportStatus::Final);

        $this->actingAs($this->staffUser(Permissions::ROLE_WORKSHOP));

        $this->expectException(ValidationException::class);
        app(FinalizeInspectionReport::class)($informe);
    }

    public function test_finalizar_queda_registrado_en_la_auditoria(): void
    {
        $organizacion = $this->organization();
        $informe = $this->reportFor($this->asset($organizacion), status: ReportStatus::Draft);

        $this->actingAs($this->staffUser(Permissions::ROLE_WORKSHOP));
        app(FinalizeInspectionReport::class)($informe);

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'inspection_report.finalized',
            'subject_id' => $informe->getKey(),
        ]);
    }

    public function test_un_informe_final_ya_no_es_editable(): void
    {
        $organizacion = $this->organization();
        $informe = $this->reportFor($this->asset($organizacion), status: ReportStatus::Final);

        $gerente = $this->staffUser(Permissions::ROLE_WORKSHOP);

        $this->assertFalse($gerente->can('update', $informe));
    }

    public function test_el_detalle_del_informe_lista_sus_piezas_y_documentos(): void
    {
        $organizacion = $this->organization();
        $activo = $this->asset($organizacion, ['description' => 'Válvula de prueba']);
        $informe = $this->reportFor($activo);

        $response = $this->actingAs($this->clientUser($organizacion))
            ->get("/informes/{$informe->public_id}");

        $response->assertOk();

        $props = $response->viewData('page')['props'];
        $this->assertSame($informe->number, $props['report']['number']);
        $this->assertCount(1, $props['lines']);
        $this->assertSame('Válvula de prueba', $props['lines'][0]['asset']['name']);
    }
}
