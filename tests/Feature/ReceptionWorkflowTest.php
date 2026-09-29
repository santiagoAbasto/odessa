<?php

namespace Tests\Feature;

use App\Models\Reception;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Recorrido del equipo en el taller.
 *
 *   Pendiente → En proceso → Aprobada ──────────────────────────────→ Despachada
 *                          ↘ Rechazada → consulta ─┬─ SÍ → vuelve a Pendiente ↗
 *                                     (lo decide    │       (se re-trabaja)
 *                                      el cliente)  └─ NO → Despachada sin reparar
 */
class ReceptionWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        // La evidencia de autorización se guarda en el disco privado: en las
        // pruebas se finge para no escribir archivos de verdad.
        Storage::fake(config('odessa.documents_disk'));
    }

    private function recepcion(array $attributes = []): Reception
    {
        $organizacion = $this->organization();

        return Reception::create([
            'organization_id' => $organizacion->getKey(),
            'asset_id' => $this->asset($organizacion)->getKey(),
            'received_at' => now()->subDays(5)->toDateString(),
            'promised_at' => now()->addDays(2)->toDateString(),
            ...$attributes,
        ]);
    }

    public function test_aprobar_registra_el_hito_y_el_usuario(): void
    {
        $recepcion = $this->recepcion();
        $gerente = $this->staffUser(Permissions::ROLE_WORKSHOP);

        $this->actingAs($gerente)
            ->post("/taller/recepciones/{$recepcion->public_id}/dictamen", ['outcome' => 'approved'])
            ->assertRedirect();

        $fresca = $recepcion->fresh();
        $this->assertNotNull($fresca->released_at);
        $this->assertSame($gerente->getKey(), $fresca->released_by);
        $this->assertDatabaseHas('activity_logs', ['action' => 'reception.approved']);
    }

    public function test_no_se_puede_despachar_una_pieza_sin_dictamen(): void
    {
        $recepcion = $this->recepcion();

        // La Policy corta antes de llegar a la acción.
        $this->actingAs($this->staffUser(Permissions::ROLE_WORKSHOP))
            ->post("/taller/recepciones/{$recepcion->public_id}/despachar")
            ->assertForbidden();

        $this->assertNull($recepcion->fresh()->dispatched_at);
    }

    public function test_una_pieza_aprobada_se_puede_despachar(): void
    {
        $recepcion = $this->recepcion(['received_at' => now()->subDays(9)->toDateString()]);
        $recepcion->forceFill(['released_at' => now()->subDay(), 'outcome' => 'approved'])->save();

        $this->actingAs($this->staffUser(Permissions::ROLE_WORKSHOP))
            ->post("/taller/recepciones/{$recepcion->public_id}/despachar", ['dispatch_note' => 'REM-00042'])
            ->assertRedirect();

        $fresca = $recepcion->fresh();
        $this->assertNotNull($fresca->dispatched_at);
        $this->assertSame('REM-00042', $fresca->dispatch_note);
    }

    public function test_no_se_puede_dictaminar_dos_veces(): void
    {
        $recepcion = $this->recepcion();
        $recepcion->forceFill(['released_at' => now()->subDay(), 'outcome' => 'approved'])->save();

        // Es un error de negocio con mensaje, no un 403: quien lo intenta
        // tiene permiso, sólo que la etapa ya pasó.
        $this->actingAs($this->staffUser(Permissions::ROLE_WORKSHOP))
            ->post("/taller/recepciones/{$recepcion->public_id}/dictamen", ['outcome' => 'approved'])
            ->assertSessionHasErrors('outcome');
    }

    public function test_una_recepcion_fuera_de_plazo_se_detecta_correctamente(): void
    {
        $vencida = $this->recepcion(['promised_at' => now()->subDays(3)->toDateString()]);
        $enFecha = $this->recepcion(['promised_at' => now()->addDays(3)->toDateString()]);

        $this->assertTrue($vencida->isOverdue());
        $this->assertFalse($enFecha->isOverdue());
        $this->assertSame(1, Reception::query()->overdue()->count());
    }

    public function test_los_filtros_por_etapa_devuelven_lo_esperado(): void
    {
        $enTaller = $this->recepcion();

        $despachada = $this->recepcion();
        $despachada->forceFill(['released_at' => now()->subDays(2), 'outcome' => 'approved', 'dispatched_at' => now()->subDay()])->save();

        $usuario = $this->staffUser(Permissions::ROLE_WORKSHOP);

        $response = $this->actingAs($usuario)->get('/taller/recepciones?stage=dispatched');
        $ids = collect($response->viewData('page')['props']['receptions']['data'])->pluck('id');

        $this->assertTrue($ids->contains($despachada->public_id));
        $this->assertFalse($ids->contains($enTaller->public_id));
    }

    public function test_la_etapa_del_pipeline_se_calcula_a_partir_de_los_hitos(): void
    {
        $recepcion = $this->recepcion();

        // Recién recibida y sin tocar: es la cola de entrada del taller.
        $this->assertSame('pending', $recepcion->stage());
        $this->assertSame('Pendiente', $recepcion->stageLabel());

        $recepcion->forceFill(['started_at' => now()])->save();
        $this->assertSame('in_progress', $recepcion->fresh()->stage());

        $recepcion->forceFill(['released_at' => now()])->save();
        $this->assertSame('approved', $recepcion->fresh()->stage());

        $recepcion->forceFill(['dispatched_at' => now()])->save();
        $this->assertSame('dispatched', $recepcion->fresh()->stage());
    }

    public function test_empezar_el_trabajo_saca_la_recepcion_de_pendientes(): void
    {
        $recepcion = $this->recepcion();
        $usuario = $this->staffUser(Permissions::ROLE_WORKSHOP);

        $this->actingAs($usuario)
            ->post("/taller/recepciones/{$recepcion->public_id}/empezar")
            ->assertRedirect();

        $recepcion->refresh();

        $this->assertNotNull($recepcion->started_at);
        $this->assertSame($usuario->getKey(), $recepcion->started_by);
        $this->assertSame('in_progress', $recepcion->stage());
    }

    public function test_no_se_puede_empezar_dos_veces_el_mismo_trabajo(): void
    {
        $recepcion = $this->recepcion();
        $recepcion->forceFill(['started_at' => now()->subHour()])->save();

        $this->actingAs($this->staffUser(Permissions::ROLE_WORKSHOP))
            ->post("/taller/recepciones/{$recepcion->public_id}/empezar")
            ->assertSessionHasErrors('started_at');
    }

    /**
     * Dictaminar sin haber marcado el inicio es habitual cuando el trabajo es
     * corto. La etapa "en proceso" no debe quedar saltada en el historial.
     */
    public function test_dictaminar_sin_haber_empezado_registra_igual_el_inicio(): void
    {
        $recepcion = $this->recepcion();

        $this->actingAs($this->staffUser(Permissions::ROLE_WORKSHOP))
            ->post("/taller/recepciones/{$recepcion->public_id}/dictamen", ['outcome' => 'approved'])
            ->assertRedirect();

        $recepcion->refresh();

        $this->assertNotNull($recepcion->started_at);
        $this->assertNotNull($recepcion->released_at);
        $this->assertSame('approved', $recepcion->stage());
    }

    public function test_rechazar_exige_motivo(): void
    {
        $recepcion = $this->recepcion();

        $this->actingAs($this->staffUser(Permissions::ROLE_WORKSHOP))
            ->post("/taller/recepciones/{$recepcion->public_id}/dictamen", ['outcome' => 'rejected'])
            ->assertSessionHasErrors('outcome_notes');

        $this->assertNull($recepcion->fresh()->released_at);
    }

    public function test_el_circuito_completo_de_rechazo_y_reparacion(): void
    {
        $recepcion = $this->recepcion();
        $usuario = $this->staffUser(Permissions::ROLE_WORKSHOP);

        $this->actingAs($usuario)
            ->post("/taller/recepciones/{$recepcion->public_id}/dictamen", [
                'outcome' => 'rejected',
                'outcome_notes' => 'Sello dañado y rosca gastada.',
            ])
            ->assertRedirect();

        $this->assertSame('rejected', $recepcion->fresh()->stage());

        // La reparación se la cobran al cliente, así que la decide el cliente.
        $this->actingAs($usuario)
            ->post("/taller/recepciones/{$recepcion->public_id}/consultar-reparacion", [
                'notes' => 'Cambio de sello y reconstrucción de rosca.',
            ])
            ->assertRedirect();

        $this->assertSame('awaiting_client', $recepcion->fresh()->stage());

        $this->actingAs($usuario)
            ->post("/taller/recepciones/{$recepcion->public_id}/autorizacion", [
                'decision' => 'approved',
                'channel' => 'email',
                'evidence' => UploadedFile::fake()->image('autorizacion.jpg'),
            ])
            ->assertRedirect();

        // Autorizar devuelve la pieza a Pendiente: se re-trabaja como cualquier otra.
        $this->assertSame('pending', $recepcion->fresh()->stage());

        $this->actingAs($usuario)->post("/taller/recepciones/{$recepcion->public_id}/empezar")->assertRedirect();
        $this->actingAs($usuario)
            ->post("/taller/recepciones/{$recepcion->public_id}/dictamen", ['outcome' => 'approved'])
            ->assertRedirect();

        $this->assertSame('approved', $recepcion->fresh()->stage());

        // Recién ahora puede salir.
        $this->actingAs($usuario)
            ->post("/taller/recepciones/{$recepcion->public_id}/despachar")
            ->assertRedirect();

        $this->assertSame('dispatched', $recepcion->fresh()->stage());
    }

    /**
     * El punto del flujo nuevo: mandarle al cliente algo rechazado y sin
     * reparar es exactamente lo que hay que impedir.
     */
    public function test_una_pieza_rechazada_no_se_puede_despachar_sin_reparar(): void
    {
        $recepcion = $this->recepcion();
        $recepcion->forceFill([
            'released_at' => now()->subDay(),
            'outcome' => 'rejected',
            'outcome_notes' => 'No pasó la prueba.',
        ])->save();

        $this->actingAs($this->staffUser(Permissions::ROLE_WORKSHOP))
            ->post("/taller/recepciones/{$recepcion->public_id}/despachar")
            ->assertForbidden();

        $this->assertNull($recepcion->fresh()->dispatched_at);
    }

    public function test_el_filtro_separa_pendientes_de_en_proceso(): void
    {
        $pendiente = $this->recepcion();

        $enProceso = $this->recepcion();
        $enProceso->forceFill(['started_at' => now()])->save();

        $usuario = $this->staffUser(Permissions::ROLE_WORKSHOP);

        $ids = fn (string $stage) => collect(
            $this->actingAs($usuario)->get("/taller/recepciones?stage={$stage}")
                ->viewData('page')['props']['receptions']['data']
        )->pluck('id');

        $this->assertTrue($ids('pending')->contains($pendiente->public_id));
        $this->assertFalse($ids('pending')->contains($enProceso->public_id));

        $this->assertTrue($ids('in_progress')->contains($enProceso->public_id));
        $this->assertFalse($ids('in_progress')->contains($pendiente->public_id));
    }
}
