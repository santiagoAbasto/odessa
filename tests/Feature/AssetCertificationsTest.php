<?php

namespace Tests\Feature;

use App\Enums\InspectionResult;
use App\Enums\ReportStatus;
use App\Models\Asset;
use App\Models\InspectionReport;
use App\Models\InspectionReportAsset;
use App\Models\Service;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * La cabecera de la ficha muestra un vencimiento por cada tipo de
 * certificación que da aptitud (COS anual, COC de conformidad…), ordenados
 * del más próximo a vencer al más lejano.
 */
class AssetCertificationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    public function test_un_vencimiento_por_tipo_ordenado_por_proximidad(): void
    {
        $org = $this->organization('HASA');
        $staff = $this->staffUser(Permissions::ROLE_WORKSHOP);
        $pieza = $this->asset($org);

        $cos = Service::create([
            'code' => 'COSNQN', 'name' => 'Certificación de Servicio',
            'determines_fitness' => true, 'validity_days' => 365, 'is_active' => true,
        ]);
        $coc = Service::create([
            'code' => 'COCNQN', 'name' => 'Certificación de Conformidad',
            'determines_fitness' => true, 'validity_days' => 1825, 'is_active' => true,
        ]);

        // El COC es la inspección más reciente (vence lejos); el COS es más
        // viejo pero vence antes. El desglose debe listar el COS primero.
        $this->certLine($pieza, $coc, today()->toDateString(), today()->addYears(5)->toDateString());
        $this->certLine($pieza, $cos, today()->subMonths(2)->toDateString(), today()->addMonths(10)->toDateString());

        $this->actingAs($staff)
            ->get("/activos/{$pieza->public_id}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('certifications', 2)
                ->where('certifications.0.code', 'COSNQN')
                ->where('certifications.1.code', 'COCNQN'));
    }

    public function test_la_operacional_no_entra_porque_no_da_aptitud(): void
    {
        $org = $this->organization('HASA');
        $staff = $this->staffUser(Permissions::ROLE_WORKSHOP);
        $pieza = $this->asset($org);

        $pop = Service::create([
            'code' => 'POPNQN', 'name' => 'Prueba operacional',
            'determines_fitness' => false, 'validity_days' => 1825, 'is_active' => true,
        ]);

        // Aun con dictamen Apto, la operacional no da aptitud: no debe figurar.
        $this->certLine($pieza, $pop, today()->toDateString(), today()->addYears(5)->toDateString());

        $this->actingAs($staff)
            ->get("/activos/{$pieza->public_id}")
            ->assertInertia(fn (AssertableInertia $page) => $page->has('certifications', 0));
    }

    private function certLine(
        Asset $asset,
        Service $service,
        string $inspectedAt,
        string $expiresAt,
        InspectionResult $result = InspectionResult::Apto,
    ): void {
        $report = InspectionReport::withoutGlobalScope('organization')->forceCreate([
            'public_id' => (string) Str::uuid7(),
            'organization_id' => $asset->organization_id,
            'number' => (string) fake()->unique()->numberBetween(1000, 999999),
            'inspected_at' => $inspectedAt,
            'status' => ReportStatus::Final->value,
            'service_id' => $service->getKey(),
        ]);

        InspectionReportAsset::create([
            'inspection_report_id' => $report->getKey(),
            'asset_id' => $asset->getKey(),
            'service_id' => $service->getKey(),
            'result' => $result->value,
            'expires_at' => $expiresAt,
        ]);
    }
}
