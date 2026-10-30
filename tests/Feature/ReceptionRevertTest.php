<?php

namespace Tests\Feature;

use App\Models\Reception;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Deshacer un dictamen cargado por error: sólo el superadministrador, sólo
 * mientras el equipo no haya seguido su curso, y siempre con un motivo que
 * queda en la auditoría.
 */
class ReceptionRevertTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    private function recepcionAprobada(): Reception
    {
        $org = $this->organization();
        $recepcion = Reception::create([
            'organization_id' => $org->getKey(),
            'asset_id' => $this->asset($org)->getKey(),
            'received_at' => now()->subDays(5)->toDateString(),
            'promised_at' => now()->addDays(2)->toDateString(),
        ]);

        $this->actingAs($this->staffUser(Permissions::ROLE_WORKSHOP))
            ->post("/taller/recepciones/{$recepcion->public_id}/dictamen", ['outcome' => 'approved']);

        return $recepcion;
    }

    public function test_el_superadmin_deshace_y_vuelve_a_en_proceso(): void
    {
        $recepcion = $this->recepcionAprobada();

        $this->actingAs($this->staffUser(Permissions::ROLE_SUPER_ADMIN))
            ->post("/taller/recepciones/{$recepcion->public_id}/deshacer", [
                'reason' => 'Se cargó en la pieza equivocada',
            ])
            ->assertRedirect();

        $fresca = $recepcion->fresh();
        $this->assertNull($fresca->released_at);
        $this->assertNull($fresca->outcome);
        // El inicio queda: vuelve a "en proceso", no a "pendiente".
        $this->assertNotNull($fresca->started_at);
        $this->assertSame('in_progress', $fresca->stage());
        $this->assertDatabaseHas('activity_logs', ['action' => 'reception.resolution_reverted']);
    }

    public function test_el_taller_no_puede_deshacer(): void
    {
        $recepcion = $this->recepcionAprobada();

        $this->actingAs($this->staffUser(Permissions::ROLE_WORKSHOP))
            ->post("/taller/recepciones/{$recepcion->public_id}/deshacer", ['reason' => 'me equivoqué'])
            ->assertForbidden();

        $this->assertNotNull($recepcion->fresh()->outcome);
    }

    public function test_no_se_deshace_una_recepcion_ya_despachada(): void
    {
        $recepcion = $this->recepcionAprobada();

        $this->actingAs($this->staffUser(Permissions::ROLE_WORKSHOP))
            ->post("/taller/recepciones/{$recepcion->public_id}/despachar");

        // El superadmin atraviesa las Policies, así que el corte lo pone la
        // acción: ya despachada no se deshace. El dictamen queda intacto.
        $this->actingAs($this->staffUser(Permissions::ROLE_SUPER_ADMIN))
            ->post("/taller/recepciones/{$recepcion->public_id}/deshacer", ['reason' => 'tarde'])
            ->assertInvalid(['outcome']);

        $fresca = $recepcion->fresh();
        $this->assertNotNull($fresca->dispatched_at);
        $this->assertSame('approved', $fresca->outcome);
    }

    public function test_deshacer_exige_un_motivo(): void
    {
        $recepcion = $this->recepcionAprobada();

        $this->actingAs($this->staffUser(Permissions::ROLE_SUPER_ADMIN))
            ->post("/taller/recepciones/{$recepcion->public_id}/deshacer", ['reason' => ''])
            ->assertInvalid(['reason']);

        $this->assertNotNull($recepcion->fresh()->outcome);
    }
}
