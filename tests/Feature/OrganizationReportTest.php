<?php

namespace Tests\Feature;

use App\Enums\AssetStatus;
use App\Enums\InspectionResult;
use App\Models\Organization;
use App\Models\Reception;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Reporte por organización: status, vencimientos y trabajos, en pantalla y
 * en Excel. Pantalla y planilla salen del mismo armado de datos.
 */
class OrganizationReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    private function reporte(\App\Models\User $user, array $query = []): array
    {
        return $this->actingAs($user)
            ->get('/reportes?'.http_build_query($query))
            ->assertOk()
            ->viewData('page')['props']['report'];
    }

    private function venceEn(Organization $org, ?int $dias, AssetStatus $estado = AssetStatus::Fit, ?int $mandarEn = null): void
    {
        $this->asset($org, [
            'status' => $estado,
            'expires_at' => $dias === null ? null : today()->addDays($dias),
            'send_by_at' => $mandarEn === null ? null : today()->addDays($mandarEn),
        ]);
    }

    public function test_un_cliente_ve_solo_lo_suyo_aunque_pida_otra_organizacion(): void
    {
        $propia = $this->organization();
        $ajena = $this->organization();
        $this->venceEn($propia, 20);
        $this->venceEn($ajena, 20);
        $this->venceEn($ajena, 20);

        $report = $this->reporte($this->clientUser($propia, Permissions::ROLE_CLIENT_VIEWER), [
            'organization' => $ajena->public_id,
        ]);

        $this->assertSame(1, $report['status']['total']);
    }

    public function test_el_personal_interno_filtra_por_organizacion_o_ve_todas(): void
    {
        $una = $this->organization();
        $otra = $this->organization();
        $this->venceEn($una, 20);
        $this->venceEn($otra, 20);
        $this->venceEn($otra, 20);
        $taller = $this->staffUser(Permissions::ROLE_WORKSHOP);

        $this->assertSame(2, $this->reporte($taller, ['organization' => $otra->public_id])['status']['total']);
        $this->assertSame(3, $this->reporte($taller)['status']['total']);
    }

    public function test_los_tramos_de_vencimiento_son_acumulados_y_cuenta_lo_fuera_de_tiempo(): void
    {
        $org = $this->organization();
        $this->venceEn($org, 10, AssetStatus::Expiring, mandarEn: -5);
        $this->venceEn($org, 45, AssetStatus::Fit, mandarEn: 0);
        $this->venceEn($org, 80);
        $this->venceEn($org, 200);
        $this->venceEn($org, -3, AssetStatus::Unfit);

        $report = $this->reporte($this->clientUser($org));

        $this->assertSame(['30' => 1, '60' => 2, '90' => 3], $report['expiry']['windows']);
        // Todavía certificado, pero ya no llega a recertificarse a tiempo.
        $this->assertSame(1, $report['expiry']['sendDeadlineMissed']);
        $this->assertSame(1, $report['status']['counts'][AssetStatus::Unfit->value]);
    }

    public function test_los_trabajos_del_periodo_cuentan_recepciones_despachos_y_fmr(): void
    {
        $org = $this->organization();
        $activo = $this->asset($org);
        $base = ['organization_id' => $org->getKey(), 'asset_id' => $activo->getKey()];

        // Recibida en el período y todavía en el taller.
        Reception::create($base + ['received_at' => today()->subDays(5)]);
        // Recibida antes del período pero despachada sin reparar dentro de él.
        Reception::create($base + ['received_at' => today()->subDays(200)])->forceFill([
            'started_at' => now()->subDays(20), 'released_at' => now()->subDays(20),
            'outcome' => Reception::OUTCOME_REJECTED, 'outcome_notes' => 'x',
            'unrepairable_at' => now()->subDays(15), 'unrepairable_notes' => 'x',
            'dispatched_at' => now()->subDays(10),
        ])->save();
        // Fuera del período del todo: entró y salió hace meses.
        Reception::create($base + ['received_at' => today()->subDays(300)])->forceFill([
            'started_at' => now()->subDays(295), 'released_at' => now()->subDays(290),
            'outcome' => Reception::OUTCOME_APPROVED, 'dispatched_at' => now()->subDays(290),
        ])->save();

        $this->reportFor($activo, InspectionResult::Apto, today()->subDays(7)->toDateString())
            ->forceFill(['finalized_at' => now()->subDays(7)])->save();
        $this->reportFor($activo, InspectionResult::Apto, today()->subDays(400)->toDateString())
            ->forceFill(['finalized_at' => now()->subDays(400)])->save();

        $report = $this->reporte($this->clientUser($org), [
            'desde' => today()->subDays(30)->toDateString(),
            'hasta' => today()->toDateString(),
        ]);

        $this->assertSame(1, $report['work']['period']['received']);
        $this->assertSame(1, $report['work']['period']['dispatched']);
        $this->assertSame(1, $report['work']['period']['returnedUnrepaired']);
        $this->assertSame(1, $report['work']['period']['reportsIssued']);
        $this->assertSame(1, $report['work']['now']['pending']);
        $this->assertCount(2, $report['work']['receptions']);
        $this->assertCount(1, $report['work']['reports']);
    }

    public function test_el_reporte_se_descarga_en_excel(): void
    {
        $org = $this->organization();
        $this->venceEn($org, 20);

        $response = $this->actingAs($this->clientUser($org))->get('/reportes/exportar');

        $response->assertOk();
        $this->assertStringContainsString('reporte-', $response->headers->get('content-disposition'));
        $this->assertNotEmpty($response->streamedContent());
    }
}
