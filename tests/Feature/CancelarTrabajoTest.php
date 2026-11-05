<?php

namespace Tests\Feature;

use App\Actions\RecalculateAssetStatus;
use App\Enums\AssetStatus;
use App\Models\ActivityLog;
use App\Models\Reception;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Cancelar un trabajo en el taller (pedido del 05/11/2026): el cliente avisa
 * que ese trabajo no se hace, el equipo pasa directo a «listo para despachar» y
 * su aptitud NO cambia.
 */
class CancelarTrabajoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    /** Una recepción con los hitos dados, sobre un equipo apto. */
    private function recepcion(array $hitos = []): Reception
    {
        $organizacion = $this->organization();
        $pieza = $this->asset($organizacion);
        $this->reportFor($pieza, expiresAt: now()->addYear()->toDateString());
        app(RecalculateAssetStatus::class)($pieza);

        $recepcion = Reception::create([
            'organization_id' => $organizacion->getKey(),
            'asset_id' => $pieza->getKey(),
            'received_at' => now()->subDays(3)->toDateString(),
        ]);
        $recepcion->forceFill($hitos)->save();

        return $recepcion->fresh();
    }

    private function cancelar(Reception $recepcion, string $motivo = 'El cliente avisó por teléfono.')
    {
        return $this->actingAs($this->staffUser(Permissions::ROLE_WORKSHOP))
            ->post("/taller/recepciones/{$recepcion->public_id}/cancelar", ['cancel_reason' => $motivo]);
    }

    /** Hitos que dejan una recepción abierta en la etapa pedida. */
    private function hitos(string $etapa): array
    {
        $rechazada = [
            'started_at' => now()->subDays(2),
            'released_at' => now()->subDay(),
            'outcome' => Reception::OUTCOME_REJECTED,
            'outcome_notes' => 'Rosca gastada.',
        ];

        return match ($etapa) {
            'pendiente' => [],
            'en proceso' => ['started_at' => now()->subDay()],
            'rechazada' => $rechazada,
            'esperando al cliente' => $rechazada + ['repair_requested_at' => now()->subDay()],
        };
    }

    /** @return array<string, array{0: string}> */
    public static function etapasAbiertas(): array
    {
        return [
            'pendiente' => ['pendiente'],
            'en proceso' => ['en proceso'],
            'rechazada' => ['rechazada'],
            'esperando al cliente' => ['esperando al cliente'],
        ];
    }

    #[DataProvider('etapasAbiertas')]
    public function test_se_cancela_desde_cualquier_etapa_abierta_y_queda_para_despachar(string $etapa): void
    {
        $recepcion = $this->recepcion($this->hitos($etapa));

        $this->cancelar($recepcion)->assertRedirect();

        $recepcion->refresh();

        $this->assertSame('cancelled', $recepcion->stage());
        $this->assertTrue($recepcion->isDispatchable());
        $this->assertSame('El cliente avisó por teléfono.', $recepcion->cancel_reason);
        $this->assertTrue(ActivityLog::query()->where('action', 'reception.cancelled')->exists());
    }

    public function test_cancelar_no_toca_la_aptitud_del_equipo(): void
    {
        $recepcion = $this->recepcion(['started_at' => now()->subDay()]);
        $antes = $recepcion->asset->fresh();

        $this->assertSame(AssetStatus::Fit, $antes->status);

        $this->cancelar($recepcion)->assertRedirect();
        $this->actingAs($this->staffUser(Permissions::ROLE_WORKSHOP))
            ->post("/taller/recepciones/{$recepcion->public_id}/despachar")
            ->assertRedirect();

        $despues = $recepcion->asset->fresh();

        $this->assertSame('returned_cancelled', $recepcion->fresh()->stage());
        $this->assertSame(AssetStatus::Fit, $despues->status);
        $this->assertEquals($antes->expires_at, $despues->expires_at);
    }

    public function test_la_cancelada_sale_de_las_colas_de_trabajo_y_entra_en_las_listas(): void
    {
        $pendiente = $this->recepcion();
        $this->cancelar($pendiente)->assertRedirect();

        $enColas = fn (string $stage) => collect(
            $this->get("/taller/recepciones?stage={$stage}")->viewData('page')['props']['receptions']['data']
        )->pluck('id');

        $this->assertFalse($enColas('pending')->contains($pendiente->public_id));
        $this->assertTrue($enColas('ready')->contains($pendiente->public_id));
        $this->assertTrue($enColas('in_workshop')->contains($pendiente->public_id));

        $rechazada = $this->recepcion($this->hitos('rechazada'));
        $this->cancelar($rechazada)->assertRedirect();

        $this->assertFalse($enColas('rejected')->contains($rechazada->public_id));
    }

    public function test_cancelar_exige_un_motivo(): void
    {
        $recepcion = $this->recepcion();

        $this->cancelar($recepcion, '')->assertSessionHasErrors('cancel_reason');

        $this->assertNull($recepcion->fresh()->cancelled_at);
    }

    public function test_no_se_cancela_lo_que_ya_esta_para_despachar_ni_lo_despachado(): void
    {
        $aprobada = $this->recepcion([
            'started_at' => now()->subDays(2), 'released_at' => now()->subDay(), 'outcome' => Reception::OUTCOME_APPROVED,
        ]);
        $this->cancelar($aprobada)->assertSessionHasErrors('cancel_reason');
        $this->assertNull($aprobada->fresh()->cancelled_at);

        $despachada = $this->recepcion(['dispatched_at' => now()]);
        $this->cancelar($despachada)->assertForbidden();
        $this->assertNull($despachada->fresh()->cancelled_at);
    }

    public function test_una_cancelada_ya_no_figura_como_vencida(): void
    {
        $recepcion = $this->recepcion(['promised_at' => now()->subDays(2)->toDateString()]);
        $this->assertTrue($recepcion->isOverdue());

        $this->cancelar($recepcion)->assertRedirect();

        $this->assertFalse($recepcion->fresh()->isOverdue());
        $this->assertSame(0, Reception::withoutGlobalScope('organization')->overdue()->count());
    }

    public function test_un_cliente_no_puede_cancelar_un_trabajo(): void
    {
        $recepcion = $this->recepcion();

        $this->actingAs($this->clientUser($recepcion->organization, Permissions::ROLE_CLIENT_OPERATOR))
            ->post("/taller/recepciones/{$recepcion->public_id}/cancelar", ['cancel_reason' => 'No.'])
            ->assertForbidden();

        $this->assertNull($recepcion->fresh()->cancelled_at);
    }
}
