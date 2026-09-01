<?php

namespace Tests\Feature;

use App\Enums\InspectionResult;
use App\Models\Reception;
use App\Models\Service;
use App\Support\AssetTimeline;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Trazabilidad por equipo y auditoría por cliente.
 *
 * El negocio lo pidió textual: por cada equipo hay que poder ver quién lo
 * hizo, qué hizo y cuándo, sin que se escape nada. Estas pruebas fijan que
 * los hitos de las distintas tablas terminen en un mismo hilo y que la
 * auditoría se pueda recortar por cliente y por equipo.
 */
class TraceabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    public function test_el_hilo_reune_hitos_de_distintas_tablas(): void
    {
        $organizacion = $this->organization();
        $pieza = $this->asset($organizacion);

        $recepcion = Reception::create([
            'organization_id' => $organizacion->getKey(),
            'asset_id' => $pieza->getKey(),
            'received_at' => now()->subDays(10)->toDateString(),
        ]);
        $recepcion->forceFill([
            'started_at' => now()->subDays(9),
            'released_at' => now()->subDays(2),
            'outcome' => 'approved',
        ])->save();

        $this->reportFor($pieza, InspectionResult::Apto, now()->subDays(5)->toDateString());

        $hilo = collect((new AssetTimeline($pieza))->build());

        $this->assertTrue($hilo->contains('title', 'Recibido en el taller'));
        $this->assertTrue($hilo->contains('title', 'Se empezó a trabajar'));
        $this->assertTrue($hilo->contains('title', 'Trabajo aprobado'));
        $this->assertTrue($hilo->contains(fn (array $e) => str_starts_with($e['title'], 'Inspección:')));
    }

    public function test_el_hilo_viene_del_mas_reciente_al_mas_antiguo(): void
    {
        $organizacion = $this->organization();
        $pieza = $this->asset($organizacion);

        Reception::create([
            'organization_id' => $organizacion->getKey(),
            'asset_id' => $pieza->getKey(),
            'received_at' => now()->subDays(30)->toDateString(),
        ]);

        $this->reportFor($pieza, InspectionResult::Apto, now()->subDay()->toDateString());

        $fechas = collect((new AssetTimeline($pieza))->build())
            ->pluck('at')
            ->filter()
            ->values();

        $this->assertSame(
            $fechas->sortDesc()->values()->all(),
            $fechas->all(),
            'El hilo no está ordenado del más reciente al más antiguo.'
        );
    }

    /** Cada hito tiene que decir quién, qué y cuándo. */
    public function test_cada_hito_trae_autor_titulo_y_fecha(): void
    {
        $organizacion = $this->organization();
        $pieza = $this->asset($organizacion);
        $usuario = $this->staffUser(Permissions::ROLE_WORKSHOP);

        Reception::create([
            'organization_id' => $organizacion->getKey(),
            'asset_id' => $pieza->getKey(),
            'received_at' => now()->toDateString(),
            'received_by' => $usuario->getKey(),
        ]);

        $ingreso = collect((new AssetTimeline($pieza))->build())
            ->firstWhere('title', 'Recibido en el taller');

        $this->assertNotNull($ingreso);
        $this->assertSame($usuario->name, $ingreso['by']);
        $this->assertNotNull($ingreso['at']);
        $this->assertNotNull($ingreso['recordedAt']);
    }

    /**
     * El hilo viaja diferido: la ficha abre enseguida y la línea de tiempo
     * llega después, porque puede traer cientos de hitos.
     */
    public function test_la_ficha_de_la_pieza_declara_el_hilo_como_diferido(): void
    {
        $organizacion = $this->organization();
        $pieza = $this->asset($organizacion);

        $response = $this->actingAs($this->staffUser(Permissions::ROLE_WORKSHOP))
            ->get("/activos/{$pieza->public_id}");

        $response->assertOk();

        $diferidas = collect($response->viewData('page')['deferredProps'] ?? [])->flatten();

        $this->assertTrue($diferidas->contains('timeline'), 'La ficha no difiere el hilo.');
    }

    public function test_la_auditoria_se_puede_filtrar_por_equipo(): void
    {
        $organizacion = $this->organization();
        $unaPieza = $this->asset($organizacion, ['serial_number' => 'PIEZA-A']);
        $otraPieza = $this->asset($organizacion, ['serial_number' => 'PIEZA-B']);

        $usuario = $this->staffUser(Permissions::ROLE_SUPER_ADMIN);

        // Consultar cada pieza deja su rastro en la auditoría.
        $this->actingAs($usuario)->get("/activos/{$unaPieza->public_id}");
        $this->actingAs($usuario)->get("/activos/{$otraPieza->public_id}");

        $response = $this->actingAs($usuario)
            ->get("/administracion/auditoria?asset={$unaPieza->public_id}");

        $filas = collect($response->viewData('page')['props']['logs']['data']);

        $this->assertTrue($filas->isNotEmpty());
        $this->assertTrue(
            $filas->every(fn (array $fila) => ($fila['asset']['id'] ?? null) === $unaPieza->public_id),
            'El filtro por equipo trajo registros de otra pieza.'
        );
    }

    public function test_la_auditoria_se_puede_filtrar_por_cliente(): void
    {
        $unaEmpresa = $this->organization('UNA');
        $otraEmpresa = $this->organization('OTRA');

        $piezaUna = $this->asset($unaEmpresa);
        $piezaOtra = $this->asset($otraEmpresa);

        $admin = $this->staffUser(Permissions::ROLE_SUPER_ADMIN);

        $this->actingAs($admin)->get("/activos/{$piezaUna->public_id}");
        $this->actingAs($admin)->get("/activos/{$piezaOtra->public_id}");

        $response = $this->actingAs($admin)
            ->get("/administracion/auditoria?organization={$unaEmpresa->public_id}");

        $filas = collect($response->viewData('page')['props']['logs']['data']);

        $this->assertTrue($filas->isNotEmpty());
        $this->assertTrue(
            $filas->every(fn (array $fila) => $fila['organization'] === $unaEmpresa->name),
            'El filtro por cliente trajo registros de otra empresa.'
        );
    }

    /** La auditoría es interna: un cliente no accede. */
    public function test_el_cliente_no_accede_a_la_auditoria(): void
    {
        $organizacion = $this->organization();

        $this->actingAs($this->clientUser($organizacion))
            ->get('/administracion/auditoria')
            ->assertForbidden();
    }

    public function test_el_superadministrador_puede_cambiar_el_tiempo_de_una_operacion(): void
    {
        $operacion = Service::create([
            'code' => 'HIDRO',
            'name' => 'Prueba hidrostática',
            'determines_fitness' => true,
            'is_active' => true,
        ]);

        $this->actingAs($this->staffUser(Permissions::ROLE_SUPER_ADMIN))
            ->put("/administracion/operaciones/{$operacion->getKey()}", [
                'name' => 'Prueba hidrostática',
                'code' => 'HIDRO',
                'determines_fitness' => true,
                'validity_days' => 365,
                'turnaround_days' => 45,
                'is_active' => true,
            ])
            ->assertRedirect();

        $this->assertSame(45, $operacion->fresh()->turnaround_days);
        $this->assertDatabaseHas('activity_logs', ['action' => 'operation.updated']);
    }

    public function test_una_operacion_no_puede_tardar_cero_dias(): void
    {
        $operacion = Service::create([
            'code' => 'VIS',
            'name' => 'Inspección visual',
            'determines_fitness' => false,
            'is_active' => true,
        ]);

        $this->actingAs($this->staffUser(Permissions::ROLE_SUPER_ADMIN))
            ->put("/administracion/operaciones/{$operacion->getKey()}", [
                'name' => 'Inspección visual',
                'code' => 'VIS',
                'determines_fitness' => false,
                'turnaround_days' => 0,
                'is_active' => true,
            ])
            ->assertSessionHasErrors('turnaround_days');
    }

    /**
     * El negocio pidió que los tiempos los puedan tocar tanto el
     * superadministrador como el taller: es el taller el que sabe cuánto
     * tarda cada cosa.
     */
    public function test_el_taller_tambien_administra_la_tabla_de_operaciones(): void
    {
        $this->actingAs($this->staffUser(Permissions::ROLE_WORKSHOP))
            ->get('/administracion/operaciones')
            ->assertOk();
    }

    public function test_un_cliente_no_puede_tocar_los_tiempos(): void
    {
        $operacion = Service::create([
            'code' => 'CERT',
            'name' => 'Certificación',
            'determines_fitness' => true,
            'is_active' => true,
        ]);

        $this->actingAs($this->clientUser($this->organization()))
            ->put("/administracion/operaciones/{$operacion->getKey()}", [
                'name' => 'Certificación',
                'code' => 'CERT',
                'determines_fitness' => true,
                'turnaround_days' => 1,
                'is_active' => true,
            ])
            ->assertForbidden();
    }
}
