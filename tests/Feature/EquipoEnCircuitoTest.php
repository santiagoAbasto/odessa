<?php

namespace Tests\Feature;

use App\Actions\RegisterDirectReception;
use App\Models\ActivityLog;
use App\Models\Asset;
use App\Models\Organization;
use App\Models\Reception;
use App\Models\Shipment;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Un equipo que ya está en camino o en el taller no se vuelve a enviar hasta que
 * se lo devuelvan (pedido del 05/11/2026), y una recepción de más —el equipo
 * recibido dos veces— se puede borrar mientras siga en espera y sin iniciar.
 */
class EquipoEnCircuitoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    private function envio(Organization $organizacion, string $status = Shipment::STATUS_DRAFT): Shipment
    {
        $shipment = Shipment::create([
            'organization_id' => $organizacion->getKey(),
            'reference' => 'ENV-2026-'.fake()->unique()->numerify('####'),
        ]);

        if ($status !== Shipment::STATUS_DRAFT) {
            $shipment->forceFill(['status' => $status, 'submitted_at' => now()])->save();
        }

        return $shipment;
    }

    private function recepcion(Asset $pieza, array $hitos = []): Reception
    {
        $recepcion = Reception::create([
            'organization_id' => $pieza->organization_id,
            'asset_id' => $pieza->getKey(),
            'received_at' => now()->subDay()->toDateString(),
        ]);

        return $recepcion->forceFill($hitos)->save() ? $recepcion->fresh() : $recepcion;
    }

    private function declarar(Organization $organizacion, Shipment $envio, Asset $pieza)
    {
        return $this->actingAs($this->clientUser($organizacion, Permissions::ROLE_CLIENT_OPERATOR))
            ->post("/envios/{$envio->public_id}/equipos", [
                'asset_public_id' => $pieza->public_id,
                'special_operation' => 'Inspección general.',
            ]);
    }

    // --- Bloqueo del reenvío ---------------------------------------------

    public function test_un_equipo_en_el_taller_no_se_puede_declarar_en_otro_envio(): void
    {
        $organizacion = $this->organization();
        $pieza = $this->asset($organizacion);
        $this->recepcion($pieza);
        $envio = $this->envio($organizacion);

        $this->declarar($organizacion, $envio, $pieza)->assertSessionHasErrors('asset_public_id');

        $this->assertSame(0, $envio->items()->count());
    }

    public function test_un_equipo_en_un_envio_remitido_no_se_puede_declarar_en_otro(): void
    {
        $organizacion = $this->organization();
        $pieza = $this->asset($organizacion);
        $this->envio($organizacion, Shipment::STATUS_SUBMITTED)->items()->create(['asset_id' => $pieza->getKey()]);
        $otro = $this->envio($organizacion);

        $this->declarar($organizacion, $otro, $pieza)->assertSessionHasErrors('asset_public_id');

        $this->assertSame(0, $otro->items()->count());
    }

    public function test_un_equipo_despachado_vuelve_a_poder_enviarse(): void
    {
        $organizacion = $this->organization();
        $pieza = $this->asset($organizacion);
        $this->recepcion($pieza, ['dispatched_at' => now()]);
        $envio = $this->envio($organizacion);

        $this->declarar($organizacion, $envio, $pieza)->assertSessionHasNoErrors();

        $this->assertSame(1, $envio->items()->count());
    }

    public function test_un_equipo_que_no_llego_no_bloquea_el_reenvio(): void
    {
        $organizacion = $this->organization();
        $pieza = $this->asset($organizacion);
        $this->envio($organizacion, Shipment::STATUS_REJECTED)->items()
            ->create(['asset_id' => $pieza->getKey(), 'not_arrived_at' => now(), 'not_arrived_reason' => 'No llegó.']);
        $envio = $this->envio($organizacion);

        $this->declarar($organizacion, $envio, $pieza)->assertSessionHasNoErrors();

        $this->assertSame(1, $envio->items()->count());
    }

    public function test_el_buscador_no_ofrece_los_equipos_que_ya_estan_en_el_circuito(): void
    {
        $organizacion = $this->organization();
        $enTaller = $this->asset($organizacion, ['serial_number' => 'EN-TALLER-1']);
        $libre = $this->asset($organizacion, ['serial_number' => 'LIBRE-1']);
        $this->recepcion($enTaller);
        $envio = $this->envio($organizacion);

        $opciones = $this->actingAs($this->clientUser($organizacion, Permissions::ROLE_CLIENT_OPERATOR))
            ->getJson("/envios/{$envio->public_id}/equipos-disponibles")
            ->assertOk()
            ->json('options');

        $ids = collect($opciones)->pluck('id');

        $this->assertTrue($ids->contains($libre->public_id));
        $this->assertFalse($ids->contains($enTaller->public_id));
    }

    public function test_al_remitir_se_vuelve_a_comprobar_el_equipo(): void
    {
        $organizacion = $this->organization();
        $pieza = $this->asset($organizacion);
        $envio = $this->envio($organizacion);
        $envio->items()->create(['asset_id' => $pieza->getKey()]);

        // Entre armar el borrador y remitirlo, el equipo entró en el taller.
        $this->recepcion($pieza);

        $this->actingAs($this->clientUser($organizacion, Permissions::ROLE_CLIENT_OPERATOR))
            ->post("/envios/{$envio->public_id}/enviar")
            ->assertSessionHasErrors('items');

        $this->assertSame(Shipment::STATUS_DRAFT, $envio->fresh()->status);
    }

    public function test_dar_recepcion_no_duplica_un_equipo_que_ya_esta_en_el_taller(): void
    {
        $organizacion = $this->organization();
        $pieza = $this->asset($organizacion);
        $this->recepcion($pieza);
        $envio = $this->envio($organizacion, Shipment::STATUS_SUBMITTED);
        $envio->items()->create(['asset_id' => $pieza->getKey()]);

        $this->actingAs($this->staffUser(Permissions::ROLE_WORKSHOP))
            ->post("/envios/{$envio->public_id}/revisar", [
                'decision' => 'authorize',
                'arrived' => $envio->items()->pluck('id')->all(),
            ])
            ->assertSessionHasErrors('arrived');

        $this->assertSame(Shipment::STATUS_SUBMITTED, $envio->fresh()->status);
        $this->assertSame(1, Reception::withoutGlobalScope('organization')->where('asset_id', $pieza->getKey())->count());
    }

    public function test_el_ingreso_directo_no_duplica_un_equipo_que_ya_esta_en_el_taller(): void
    {
        $organizacion = $this->organization();
        $pieza = $this->asset($organizacion);
        $this->recepcion($pieza);
        $this->actingAs($this->staffUser(Permissions::ROLE_WORKSHOP));

        $this->expectException(ValidationException::class);

        app(RegisterDirectReception::class)($organizacion, [
            'asset_public_id' => $pieza->public_id,
            'received_at' => today()->toDateString(),
        ]);
    }

    // --- Eliminar una recepción repetida ---------------------------------

    public function test_el_taller_elimina_una_recepcion_en_espera_y_queda_en_la_auditoria(): void
    {
        $organizacion = $this->organization();
        $pieza = $this->asset($organizacion);
        $recepcion = $this->recepcion($pieza);

        $this->actingAs($this->staffUser(Permissions::ROLE_WORKSHOP))
            ->post("/taller/recepciones/{$recepcion->public_id}/eliminar", ['reason' => 'Se recibió dos veces.'])
            ->assertRedirect();

        $this->assertSoftDeleted('receptions', ['id' => $recepcion->getKey()]);
        $this->assertTrue(ActivityLog::query()->where('action', 'reception.deleted')->exists());
        // Y el equipo deja de estar en el circuito: se puede volver a enviar.
        $this->assertSame(0, Asset::query()->whereKey($pieza->getKey())->inCircuit()->count());
    }

    public function test_eliminar_exige_un_motivo(): void
    {
        $recepcion = $this->recepcion($this->asset($this->organization()));

        $this->actingAs($this->staffUser(Permissions::ROLE_WORKSHOP))
            ->post("/taller/recepciones/{$recepcion->public_id}/eliminar", ['reason' => ''])
            ->assertSessionHasErrors('reason');

        $this->assertNotSoftDeleted('receptions', ['id' => $recepcion->getKey()]);
    }

    public function test_una_recepcion_ya_iniciada_no_se_elimina(): void
    {
        $recepcion = $this->recepcion($this->asset($this->organization()), ['started_at' => now()]);

        // El taller no tiene el permiso sobre lo ya iniciado…
        $this->actingAs($this->staffUser(Permissions::ROLE_WORKSHOP))
            ->post("/taller/recepciones/{$recepcion->public_id}/eliminar", ['reason' => 'Repetida.'])
            ->assertForbidden();

        // …y el superadmin, que atraviesa las policies, tropieza con la regla de la acción.
        $this->actingAs($this->staffUser(Permissions::ROLE_SUPER_ADMIN))
            ->post("/taller/recepciones/{$recepcion->public_id}/eliminar", ['reason' => 'Repetida.'])
            ->assertSessionHasErrors('reason');

        $this->assertNotSoftDeleted('receptions', ['id' => $recepcion->getKey()]);
    }

    public function test_un_cliente_no_puede_eliminar_una_recepcion(): void
    {
        $organizacion = $this->organization();
        $recepcion = $this->recepcion($this->asset($organizacion));

        $this->actingAs($this->clientUser($organizacion, Permissions::ROLE_CLIENT_OPERATOR))
            ->post("/taller/recepciones/{$recepcion->public_id}/eliminar", ['reason' => 'Repetida.'])
            ->assertForbidden();

        $this->assertNotSoftDeleted('receptions', ['id' => $recepcion->getKey()]);
    }
}
