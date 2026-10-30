<?php

namespace Tests\Feature;

use App\Actions\RecalculateAssetStatus;
use App\Enums\AssetStatus;
use App\Enums\InspectionResult;
use App\Enums\ReportStatus;
use App\Models\Asset;
use App\Models\InspectionReport;
use App\Models\InspectionReportAsset;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * La aptitud mira todas las certificaciones, no sólo la última: una pieza está
 * apta sólo si está al día en cada tipo, y manda el vencimiento más próximo.
 */
class AssetFitnessByTypeTest extends TestCase
{
    use RefreshDatabase;

    public function test_manda_el_vencimiento_mas_proximo_entre_tipos(): void
    {
        [$cos, $coc] = $this->servicios();
        $pieza = $this->asset($this->organization('HASA'));

        // COC es la inspección más reciente y vence lejos; el COS es más viejo
        // y vence antes. La pieza está apta y el vencimiento que manda es el COS.
        $this->certLine($pieza, $coc, today()->toDateString(), today()->addYears(5)->toDateString());
        $this->certLine($pieza, $cos, today()->subMonths(2)->toDateString(), today()->addMonths(10)->toDateString());

        app(RecalculateAssetStatus::class)($pieza);
        $pieza->refresh();

        $this->assertSame(AssetStatus::Fit, $pieza->status);
        $this->assertSame(today()->addMonths(10)->toDateString(), $pieza->expires_at?->toDateString());
    }

    public function test_un_tipo_vencido_deja_la_pieza_no_apta_aunque_el_otro_este_al_dia(): void
    {
        [$cos, $coc] = $this->servicios();
        $pieza = $this->asset($this->organization('HASA'));

        // El COC está al día (5 años), pero el COS anual venció ayer.
        $this->certLine($pieza, $coc, today()->subMonths(3)->toDateString(), today()->addYears(4)->toDateString());
        $this->certLine($pieza, $cos, today()->subYear()->toDateString(), today()->subDay()->toDateString());

        app(RecalculateAssetStatus::class)($pieza);
        $pieza->refresh();

        $this->assertSame(AssetStatus::Unfit, $pieza->status);
    }

    public function test_un_tipo_no_apto_deja_la_pieza_no_apta(): void
    {
        [$cos, $coc] = $this->servicios();
        $pieza = $this->asset($this->organization('HASA'));

        $this->certLine($pieza, $cos, today()->toDateString(), today()->addMonths(10)->toDateString());
        $this->certLine($pieza, $coc, today()->subMonth()->toDateString(), null, InspectionResult::NoApto);

        app(RecalculateAssetStatus::class)($pieza);
        $pieza->refresh();

        $this->assertSame(AssetStatus::Unfit, $pieza->status);
        $this->assertNull($pieza->expires_at);
    }

    /** @return array{0: Service, 1: Service} */
    private function servicios(): array
    {
        return [
            Service::create([
                'code' => 'COSNQN', 'name' => 'Certificación de Servicio',
                'determines_fitness' => true, 'validity_days' => 365, 'is_active' => true,
            ]),
            Service::create([
                'code' => 'COCNQN', 'name' => 'Certificación de Conformidad',
                'determines_fitness' => true, 'validity_days' => 1825, 'is_active' => true,
            ]),
        ];
    }

    private function certLine(
        Asset $asset,
        Service $service,
        string $inspectedAt,
        ?string $expiresAt,
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
