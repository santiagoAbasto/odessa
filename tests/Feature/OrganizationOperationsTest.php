<?php

namespace Tests\Feature;

use App\Actions\FinalizeInspectionReport;
use App\Enums\InspectionResult;
use App\Enums\ReportStatus;
use App\Models\Organization;
use App\Models\Reception;
use App\Models\Service;
use App\Models\Shipment;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Operaciones por empresa: Odessa define qué operaciones tiene cada cliente,
 * con nombre, descripción y vigencia propios, y el cliente pide por cada
 * equipo del envío cuáles aplican —o una operación especial descripta—.
 */
class OrganizationOperationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    private function operacion(string $nombre, ?int $vigencia = 365): Service
    {
        return Service::create([
            'code' => strtoupper(substr($nombre, 0, 3)).random_int(10, 99),
            'name' => $nombre,
            'determines_fitness' => true,
            'validity_days' => $vigencia,
            'description' => "Descripción general de {$nombre}.",
        ]);
    }

    private function habilitar(Organization $org, Service $op, array $ajustes = []): void
    {
        $org->operations()->attach($op->getKey(), $ajustes);
    }

    private function envio(Organization $org): Shipment
    {
        return Shipment::create([
            'organization_id' => $org->getKey(),
            'reference' => 'ENV-2026-'.fake()->unique()->numerify('####'),
        ]);
    }

    // --- Configuración por empresa -----------------------------------------

    public function test_el_superadmin_habilita_operaciones_con_nombre_y_vigencia_propios(): void
    {
        $org = $this->organization();
        $torque = $this->operacion('Torque');
        $prueba = $this->operacion('Prueba hidráulica');

        $this->actingAs($this->staffUser(Permissions::ROLE_SUPER_ADMIN))
            ->put("/administracion/organizaciones/{$org->public_id}/operaciones", [
                'operations' => [
                    ['service_id' => $torque->getKey(), 'enabled' => true, 'name' => 'Torqueo de conexiones',
                        'description' => 'Torque a valores del fabricante.', 'validity_days' => 180],
                    ['service_id' => $prueba->getKey(), 'enabled' => false],
                ],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $habilitadas = $org->operations()->get();

        $this->assertCount(1, $habilitadas);
        $this->assertSame('Torqueo de conexiones', $habilitadas->first()->pivot->name);
        $this->assertSame(180, (int) $habilitadas->first()->pivot->validity_days);
        $this->assertDatabaseHas('activity_logs', ['action' => 'organization.operations_updated']);
    }

    public function test_el_listado_de_operaciones_de_una_empresa_trae_el_catalogo_con_sus_ajustes(): void
    {
        $org = $this->organization();
        $torque = $this->operacion('Torque');
        $this->operacion('Prueba hidráulica');
        $this->habilitar($org, $torque, ['name' => 'Torqueo']);

        $json = $this->actingAs($this->staffUser(Permissions::ROLE_SUPER_ADMIN))
            ->getJson("/administracion/organizaciones/{$org->public_id}/operaciones")
            ->assertOk()
            ->json();

        $this->assertCount(2, $json);
        $fila = collect($json)->firstWhere('serviceId', $torque->getKey());
        $this->assertTrue($fila['enabled']);
        $this->assertSame('Torqueo', $fila['name']);
    }

    public function test_el_taller_no_define_operaciones_por_empresa(): void
    {
        $org = $this->organization();

        $this->actingAs($this->staffUser(Permissions::ROLE_WORKSHOP))
            ->put("/administracion/organizaciones/{$org->public_id}/operaciones", ['operations' => []])
            ->assertForbidden();
    }

    public function test_la_descripcion_del_catalogo_se_guarda(): void
    {
        $op = $this->operacion('Torque');

        $this->actingAs($this->staffUser(Permissions::ROLE_SUPER_ADMIN))
            ->put("/administracion/operaciones/{$op->getKey()}", [
                'name' => 'Torque', 'code' => $op->code, 'determines_fitness' => true,
                'validity_days' => 365, 'turnaround_days' => null, 'is_active' => true,
                'description' => 'Torque de todas las conexiones.',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('Torque de todas las conexiones.', $op->fresh()->description);
    }

    // --- Pedido por equipo -------------------------------------------------

    public function test_el_cliente_pide_operaciones_habilitadas_para_cada_equipo(): void
    {
        $org = $this->organization();
        $torque = $this->operacion('Torque');
        $prueba = $this->operacion('Prueba hidráulica');
        $this->habilitar($org, $torque);
        $this->habilitar($org, $prueba);
        $envio = $this->envio($org);

        $this->actingAs($this->clientUser($org))
            ->post("/envios/{$envio->public_id}/equipos", [
                'declared_description' => 'Válvula 3"',
                'operations' => [$torque->getKey(), $prueba->getKey()],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $item = $envio->items()->first();
        $this->assertEqualsCanonicalizing([$torque->getKey(), $prueba->getKey()], $item->operations->pluck('id')->all());
    }

    public function test_no_se_pide_una_operacion_no_habilitada_para_la_empresa(): void
    {
        $org = $this->organization();
        $otra = $this->organization();
        $ajena = $this->operacion('Sólo para otra');
        $this->habilitar($otra, $ajena);
        $envio = $this->envio($org);

        $this->actingAs($this->clientUser($org))
            ->post("/envios/{$envio->public_id}/equipos", [
                'declared_description' => 'Válvula 3"',
                'operations' => [$ajena->getKey()],
            ])
            ->assertSessionHasErrors('operations.0');

        $this->assertSame(0, $envio->items()->count());
    }

    public function test_cada_equipo_tiene_que_tener_su_solicitud(): void
    {
        $org = $this->organization();
        $envio = $this->envio($org);

        $this->actingAs($this->clientUser($org))
            ->post("/envios/{$envio->public_id}/equipos", ['declared_description' => 'Válvula 3"'])
            ->assertSessionHasErrors('operations');

        $this->assertSame(0, $envio->items()->count());
    }

    public function test_la_operacion_especial_alcanza_si_viene_descripta(): void
    {
        $org = $this->organization();
        $envio = $this->envio($org);

        $this->actingAs($this->clientUser($org))
            ->post("/envios/{$envio->public_id}/equipos", [
                'declared_description' => 'Válvula 3"',
                'special_operation' => 'Cambio de sellos y prueba a 10.000 psi.',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('Cambio de sellos y prueba a 10.000 psi.', $envio->items()->first()->special_operation);
    }

    public function test_el_detalle_del_envio_muestra_el_nombre_de_la_empresa_y_lo_pedido(): void
    {
        $org = $this->organization();
        $torque = $this->operacion('Torque');
        $this->habilitar($org, $torque, ['name' => 'Torqueo de conexiones']);
        $envio = $this->envio($org);
        $item = $envio->items()->create(['declared_description' => 'Válvula', 'special_operation' => 'Algo más']);
        $item->operations()->attach($torque->getKey());

        $props = $this->actingAs($this->clientUser($org))
            ->get("/envios/{$envio->public_id}")
            ->assertOk()
            ->viewData('page')['props'];

        $this->assertSame('Torqueo de conexiones', $props['items'][0]['operations'][0]);
        $this->assertSame('Algo más', $props['items'][0]['specialOperation']);
        $this->assertSame('Torqueo de conexiones', $props['operationOptions'][0]['name']);
    }

    public function test_la_fila_del_taller_trae_lo_que_pidio_el_cliente(): void
    {
        $org = $this->organization();
        $torque = $this->operacion('Torque');
        $this->habilitar($org, $torque, ['name' => 'Torqueo']);
        $envio = $this->envio($org);
        $reception = Reception::create([
            'organization_id' => $org->getKey(),
            'asset_id' => $this->asset($org)->getKey(),
            'shipment_id' => $envio->getKey(),
            'received_at' => today()->toDateString(),
        ]);
        $item = $envio->items()->create(['declared_description' => 'Válvula', 'special_operation' => 'Revisar rosca']);
        $item->operations()->attach($torque->getKey());
        $item->reception()->associate($reception)->save();

        $fila = collect($this->actingAs($this->staffUser(Permissions::ROLE_WORKSHOP))
            ->get('/taller/recepciones?stage=all')
            ->viewData('page')['props']['receptions']['data'])->firstWhere('id', $reception->public_id);

        $this->assertSame(["Torqueo ({$torque->code})"], $fila['requested']);
        $this->assertSame('Revisar rosca', $fila['specialOperation']);
    }

    // --- Vigencia ----------------------------------------------------------

    public function test_el_fmr_usa_la_vigencia_que_la_empresa_tiene_para_esa_operacion(): void
    {
        $org = $this->organization();
        $op = $this->operacion('Certificación', 365);
        $this->habilitar($org, $op, ['validity_days' => 90]);
        $activo = $this->asset($org);

        $report = $this->reportFor($activo, InspectionResult::Apto, '2026-09-01', null, ReportStatus::Draft);
        $report->forceFill(['service_id' => $op->getKey()])->save();

        $this->actingAs($this->staffUser(Permissions::ROLE_WORKSHOP));
        app(FinalizeInspectionReport::class)($report);

        // 01/09 + 90 días de la empresa, no los 365 del catálogo.
        $this->assertSame('2026-11-30', $report->lines()->first()->expires_at?->toDateString());
    }
}
