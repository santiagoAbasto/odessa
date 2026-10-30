<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Reception;
use App\Models\Shipment;
use App\Notifications\ShipmentItemsNotArrived;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Recepción parcial: el taller recibe lo que llegó y marca lo que no, con su
 * motivo. Lo no llegado no entra al taller y se le avisa al operador.
 */
class ShipmentPartialReceptionTest extends TestCase
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
        ]);
        $envio->forceFill(['status' => Shipment::STATUS_SUBMITTED, 'submitted_at' => now()])->save();

        for ($i = 0; $i < $equipos; $i++) {
            $envio->items()->create(['asset_id' => $this->asset($org)->getKey()]);
        }

        return $envio;
    }

    public function test_ingresa_lo_que_llego_y_avisa_al_operador_lo_que_no(): void
    {
        Notification::fake();
        $org = $this->organization();
        $operador = $this->clientUser($org, Permissions::ROLE_CLIENT_OPERATOR);
        $envio = $this->remitido($org, 2);
        $items = $envio->items()->orderBy('id')->get();
        $llego = $items->first();
        $falta = $items->get(1);

        $this->actingAs($this->staffUser(Permissions::ROLE_WORKSHOP))
            ->post("/envios/{$envio->public_id}/revisar", [
                'decision' => 'authorize',
                'arrived' => [$llego->id],
                'reasons' => [$falta->id => 'No estaba en el cajón.'],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $envio->refresh();
        $this->assertSame(Shipment::STATUS_AUTHORIZED, $envio->status);

        // Sólo el que llegó entró al taller.
        $this->assertSame(1, Reception::withoutGlobalScope('organization')
            ->where('shipment_id', $envio->getKey())->count());

        // El que no llegó quedó marcado con su motivo y sin recepción.
        $falta->refresh();
        $this->assertNotNull($falta->not_arrived_at);
        $this->assertSame('No estaba en el cajón.', $falta->not_arrived_reason);
        $this->assertNull($falta->reception_id);

        Notification::assertSentTo($operador, ShipmentItemsNotArrived::class);
    }

    public function test_si_no_llego_ninguno_el_envio_queda_rechazado(): void
    {
        Notification::fake();
        $org = $this->organization();
        $operador = $this->clientUser($org, Permissions::ROLE_CLIENT_OPERATOR);
        $envio = $this->remitido($org, 2);
        $ids = $envio->items()->pluck('id')->all();

        $this->actingAs($this->staffUser(Permissions::ROLE_WORKSHOP))
            ->post("/envios/{$envio->public_id}/revisar", [
                'decision' => 'authorize',
                'arrived' => [],
                'reasons' => [$ids[0] => 'No llegó', $ids[1] => 'No llegó'],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(Shipment::STATUS_REJECTED, $envio->fresh()->status);
        $this->assertSame(0, Reception::withoutGlobalScope('organization')
            ->where('shipment_id', $envio->getKey())->count());

        Notification::assertSentTo($operador, ShipmentItemsNotArrived::class);
    }
}
