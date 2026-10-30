<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Reception;
use App\Models\Service;
use App\Models\Shipment;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * «Pendiente de recepción»: el paso anterior a En taller.
 *
 * El cliente remite el envío; cuando llega, el taller lo ve en Recepción de
 * equipos, controla pieza por pieza que llegó lo declarado y recién ahí le da
 * recepción. Si algo no cuadra, rechaza el envío con el motivo.
 */
class ShipmentArrivalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    private function remitido(Organization $org, int $equipos = 2): Shipment
    {
        $envio = Shipment::create([
            'organization_id' => $org->getKey(),
            'reference' => 'ENV-2026-'.fake()->unique()->numerify('####'),
            'notes' => 'Recertificación anual.',
        ]);
        $envio->forceFill(['status' => Shipment::STATUS_SUBMITTED, 'submitted_at' => now()])->save();

        for ($i = 0; $i < $equipos; $i++) {
            $envio->items()->create(['asset_id' => $this->asset($org)->getKey()]);
        }

        return $envio;
    }

    public function test_no_se_recibe_si_falta_el_motivo_de_lo_que_no_llego(): void
    {
        $envio = $this->remitido($this->organization());
        $items = $envio->items()->orderBy('id')->get();
        $primero = $items->first();
        $segundo = $items->get(1);

        // Llegó el primero; el segundo no, pero no se dijo por qué: no avanza.
        $this->actingAs($this->staffUser(Permissions::ROLE_WORKSHOP))
            ->post("/envios/{$envio->public_id}/revisar", ['decision' => 'authorize', 'arrived' => [$primero->id]])
            ->assertSessionHasErrors("reasons.{$segundo->id}");

        $this->assertSame(Shipment::STATUS_SUBMITTED, $envio->fresh()->status);
        $this->assertSame(0, Reception::withoutGlobalScope('organization')->count());
    }

    public function test_un_equipo_de_otro_envio_no_cuenta_como_llegado(): void
    {
        $org = $this->organization();
        $envio = $this->remitido($org, 1);
        $otro = $this->remitido($org, 1);

        // Confirmar con una línea de otro envío no recibe nada: la propia queda
        // como no llegada y, sin motivo, la recepción no avanza.
        $this->actingAs($this->staffUser(Permissions::ROLE_WORKSHOP))
            ->post("/envios/{$envio->public_id}/revisar", [
                'decision' => 'authorize',
                'arrived' => [$otro->items()->first()->id],
            ])
            ->assertSessionHasErrors('reasons.'.$envio->items()->first()->id);

        $this->assertSame(Shipment::STATUS_SUBMITTED, $envio->fresh()->status);
    }

    public function test_confirmado_todo_entra_al_taller_con_las_observaciones_de_llegada(): void
    {
        $envio = $this->remitido($this->organization());

        $this->actingAs($this->staffUser(Permissions::ROLE_WORKSHOP))
            ->post("/envios/{$envio->public_id}/revisar", [
                'decision' => 'authorize',
                'arrived' => $envio->items()->pluck('id')->all(),
                'review_notes' => 'Llegó en cajón de madera, sin golpes.',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $fresco = $envio->fresh();
        $this->assertSame(Shipment::STATUS_AUTHORIZED, $fresco->status);
        $this->assertSame('Llegó en cajón de madera, sin golpes.', $fresco->review_notes);
        $this->assertSame(2, Reception::withoutGlobalScope('organization')->where('shipment_id', $envio->getKey())->count());
    }

    public function test_la_pestana_pendiente_de_recepcion_muestra_lo_remitido_con_lo_que_pide(): void
    {
        $org = $this->organization();
        $torque = Service::create(['code' => 'TQ1', 'name' => 'Torque', 'determines_fitness' => true]);
        $org->operations()->attach($torque->getKey(), ['name' => 'Torqueo']);

        $remitido = $this->remitido($org, 1);
        $remitido->items()->first()->operations()->attach($torque->getKey());
        $remitido->items()->first()->update(['special_operation' => 'Cambio de sellos']);

        // Un borrador todavía no se mandó: no tiene nada que recibir.
        Shipment::create(['organization_id' => $org->getKey(), 'reference' => 'ENV-2026-9999']);

        $props = $this->actingAs($this->staffUser(Permissions::ROLE_WORKSHOP))
            ->get('/taller/recepciones?stage=incoming')
            ->assertOk()
            ->viewData('page')['props'];

        $this->assertCount(1, $props['incoming']);
        $envio = $props['incoming'][0];
        $this->assertSame($remitido->public_id, $envio['id']);
        $this->assertSame('Recertificación anual.', $envio['notes']);
        $this->assertTrue($envio['canReceive']);
        $this->assertSame(['Torqueo (TQ1)'], $envio['items'][0]['operations']);
        $this->assertSame('Cambio de sellos', $envio['items'][0]['specialOperation']);
        $this->assertSame([], $props['receptions']['data']);
    }

    public function test_un_cliente_ve_solo_sus_envios_pendientes_y_no_puede_recibirlos(): void
    {
        $propia = $this->organization();
        $this->remitido($propia, 1);
        $this->remitido($this->organization(), 1);

        $incoming = $this->actingAs($this->clientUser($propia))
            ->get('/taller/recepciones?stage=incoming')
            ->viewData('page')['props']['incoming'];

        $this->assertCount(1, $incoming);
        $this->assertFalse($incoming[0]['canReceive']);
    }

    /**
     * El superadmin atraviesa las Policies con Gate::before: el botón de
     * responder aparecía en todas las filas, no sólo en las que esperan al
     * cliente.
     */
    public function test_registrar_respuesta_solo_se_ofrece_si_espera_al_cliente(): void
    {
        $org = $this->organization();
        $pendiente = Reception::create([
            'organization_id' => $org->getKey(),
            'asset_id' => $this->asset($org)->getKey(),
            'received_at' => today(),
        ]);
        $esperando = Reception::create([
            'organization_id' => $org->getKey(),
            'asset_id' => $this->asset($org)->getKey(),
            'received_at' => today(),
        ]);
        $esperando->forceFill([
            'started_at' => now(), 'released_at' => now(), 'outcome' => Reception::OUTCOME_REJECTED,
            'outcome_notes' => 'x', 'repair_requested_at' => now(),
        ])->save();

        $filas = collect($this->actingAs($this->staffUser(Permissions::ROLE_SUPER_ADMIN))
            ->get('/taller/recepciones?stage=all')
            ->viewData('page')['props']['receptions']['data'])->keyBy('id');

        $this->assertFalse($filas[$pendiente->public_id]['can']['decideRepair']);
        $this->assertTrue($filas[$esperando->public_id]['can']['decideRepair']);
    }
}
