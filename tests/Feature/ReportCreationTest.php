<?php

namespace Tests\Feature;

use App\Enums\AssetStatus;
use App\Enums\InspectionResult;
use App\Enums\ReportStatus;
use App\Models\InspectionReport;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Alta y carga de un FMR, tal como lo describió el negocio:
 *
 *   «primero hacer un informe, selecciono equipos, genera fecha certificado
 *    nueva se actualiza, y después ahí finalizo, y me cambia la condición
 *    pasa a apto, y pueden aprobar a un equipo varios»
 *
 * La pieza clave es que nada de esto toca el estado de un equipo hasta el
 * cierre: un borrador es trabajo en curso, no un dictamen.
 */
class ReportCreationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    public function test_el_recorrido_completo_deja_los_equipos_aptos(): void
    {
        $organizacion = $this->organization('HAL');
        $usuario = $this->staffUser(Permissions::ROLE_WORKSHOP);
        $uno = $this->asset($organizacion);
        $dos = $this->asset($organizacion);

        // 1. El informe nace en borrador.
        $this->actingAs($usuario)
            ->post('/informes', [
                'organization_public_id' => $organizacion->public_id,
                'number' => 'FMR-5001',
                'inspected_at' => today()->toDateString(),
            ])
            ->assertRedirect();

        $informe = InspectionReport::withoutGlobalScopes()->where('number', 'FMR-5001')->firstOrFail();
        $this->assertSame(ReportStatus::Draft, $informe->status);

        // 2. Se le suman los equipos, de a varios.
        $this->actingAs($usuario)
            ->post("/informes/{$informe->public_id}/equipos", [
                'asset_public_ids' => [$uno->public_id, $dos->public_id],
            ])
            ->assertRedirect();

        $this->assertSame(2, $informe->lines()->count());
        // Entran sin dictaminar: todavía no dicen nada sobre la pieza.
        $this->assertSame(2, $informe->lines()->where('result', InspectionResult::SinProcesar->value)->count());
        $this->assertSame(AssetStatus::Unknown, $uno->fresh()->status);

        // 3. Se dictaminan los dos de una, con su vencimiento.
        $vence = today()->addYear();

        $this->actingAs($usuario)
            ->post("/informes/{$informe->public_id}/dictamen", [
                'line_ids' => $informe->lines()->pluck('id')->all(),
                'result' => 'apto',
                'expires_at' => $vence->toDateString(),
            ])
            ->assertRedirect();

        // Dictaminado pero sin cerrar: la pieza sigue sin estado.
        $this->assertSame(AssetStatus::Unknown, $uno->fresh()->status);

        // 4. Al finalizar, recién ahí cambia la condición.
        $this->actingAs($this->staffUser(Permissions::ROLE_SUPER_ADMIN))
            ->post("/informes/{$informe->public_id}/finalizar")
            ->assertRedirect();

        $this->assertSame(ReportStatus::Final, $informe->fresh()->status);

        foreach ([$uno, $dos] as $activo) {
            $fresco = $activo->fresh();
            $this->assertSame(AssetStatus::Fit, $fresco->status);
            $this->assertSame($vence->toDateString(), $fresco->expires_at?->toDateString());
        }
    }

    /**
     * Sin vencimiento explícito, el cierre lo calcula desde la vigencia de la
     * operación. Es el caso habitual: el taller no tipea la fecha, la deriva.
     */
    public function test_sin_vencimiento_el_cierre_lo_calcula(): void
    {
        $organizacion = $this->organization('HAL');
        $usuario = $this->staffUser(Permissions::ROLE_WORKSHOP);
        $activo = $this->asset($organizacion);

        $informe = InspectionReport::create([
            'organization_id' => $organizacion->getKey(),
            'number' => 'FMR-5002',
            'inspected_at' => today(),
            'status' => ReportStatus::Draft,
        ]);

        $this->actingAs($usuario)
            ->post("/informes/{$informe->public_id}/equipos", ['asset_public_ids' => [$activo->public_id]])
            ->assertRedirect();

        $this->actingAs($usuario)
            ->post("/informes/{$informe->public_id}/dictamen", [
                'line_ids' => $informe->lines()->pluck('id')->all(),
                'result' => 'apto',
            ])
            ->assertRedirect();

        $this->actingAs($this->staffUser(Permissions::ROLE_SUPER_ADMIN))
            ->post("/informes/{$informe->public_id}/finalizar")
            ->assertRedirect();

        $fresco = $activo->fresh();
        $this->assertSame(AssetStatus::Fit, $fresco->status);
        $this->assertNotNull($fresco->expires_at);
    }

    /** Un dictamen que no certifica no puede arrastrar un vencimiento. */
    public function test_un_no_apto_no_conserva_vencimiento(): void
    {
        $organizacion = $this->organization('HAL');
        $usuario = $this->staffUser(Permissions::ROLE_WORKSHOP);
        $activo = $this->asset($organizacion);

        $informe = InspectionReport::create([
            'organization_id' => $organizacion->getKey(),
            'number' => 'FMR-5003',
            'inspected_at' => today(),
            'status' => ReportStatus::Draft,
        ]);

        $this->actingAs($usuario)
            ->post("/informes/{$informe->public_id}/equipos", ['asset_public_ids' => [$activo->public_id]])
            ->assertRedirect();

        $this->actingAs($usuario)
            ->post("/informes/{$informe->public_id}/dictamen", [
                'line_ids' => $informe->lines()->pluck('id')->all(),
                'result' => 'no_apto',
                // Aunque se mande, se descarta: el dictamen no lo respalda.
                'expires_at' => today()->addYear()->toDateString(),
                'findings' => 'Rosca gastada.',
            ])
            ->assertRedirect();

        $this->assertNull($informe->lines()->first()->expires_at);
    }

    public function test_el_numero_de_fmr_no_se_repite_en_la_misma_empresa(): void
    {
        $organizacion = $this->organization('HAL');

        InspectionReport::create([
            'organization_id' => $organizacion->getKey(),
            'number' => 'FMR-7777',
            'inspected_at' => today(),
            'status' => ReportStatus::Draft,
        ]);

        $this->actingAs($this->staffUser(Permissions::ROLE_WORKSHOP))
            ->post('/informes', [
                'organization_public_id' => $organizacion->public_id,
                'number' => 'FMR-7777',
                'inspected_at' => today()->toDateString(),
            ])
            ->assertSessionHasErrors('number');
    }

    /** Un FMR no mezcla piezas de dos clientes. */
    public function test_no_se_agregan_equipos_de_otra_empresa(): void
    {
        $una = $this->organization('UNO');
        $otra = $this->organization('DOS');
        $ajeno = $this->asset($otra);

        $informe = InspectionReport::create([
            'organization_id' => $una->getKey(),
            'number' => 'FMR-8001',
            'inspected_at' => today(),
            'status' => ReportStatus::Draft,
        ]);

        $this->actingAs($this->staffUser(Permissions::ROLE_WORKSHOP))
            ->post("/informes/{$informe->public_id}/equipos", ['asset_public_ids' => [$ajeno->public_id]])
            ->assertSessionHasErrors('asset_public_ids');

        $this->assertSame(0, $informe->lines()->count());
    }

    /** Reintentar no puede duplicar la línea ni pisar lo ya dictaminado. */
    public function test_agregar_dos_veces_el_mismo_equipo_no_duplica(): void
    {
        $organizacion = $this->organization('HAL');
        $usuario = $this->staffUser(Permissions::ROLE_WORKSHOP);
        $activo = $this->asset($organizacion);

        $informe = InspectionReport::create([
            'organization_id' => $organizacion->getKey(),
            'number' => 'FMR-8002',
            'inspected_at' => today(),
            'status' => ReportStatus::Draft,
        ]);

        foreach ([1, 2] as $_) {
            $this->actingAs($usuario)
                ->post("/informes/{$informe->public_id}/equipos", ['asset_public_ids' => [$activo->public_id]])
                ->assertRedirect();
        }

        $this->assertSame(1, $informe->lines()->count());
    }

    public function test_un_cliente_no_crea_informes(): void
    {
        $organizacion = $this->organization('UNO');

        $this->actingAs($this->clientUser($organizacion, Permissions::ROLE_CLIENT_OPERATOR))
            ->post('/informes', [
                'organization_public_id' => $organizacion->public_id,
                'number' => 'FMR-9999',
                'inspected_at' => today()->toDateString(),
            ])
            ->assertForbidden();
    }
}
