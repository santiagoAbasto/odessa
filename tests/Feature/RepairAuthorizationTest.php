<?php

namespace Tests\Feature;

use App\Actions\RecalculateAssetStatus;
use App\Enums\AssetStatus;
use App\Enums\InspectionResult;
use App\Models\Document;
use App\Models\Organization;
use App\Models\Reception;
use App\Notifications\RepairAuthorizationRequested;
use App\Support\AssetTimeline;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * La reparación la decide el cliente, no el taller.
 *
 *   Rechazada → consulta ─┬─ SÍ  → vuelve a Pendiente (se re-trabaja) → Despachada
 *                          └─ NO  → Despachada sin reparar (queda no apta)
 *
 * Dos cosas se prueban acá y las dos importan por el mismo motivo, que es el
 * cobro: que la decisión quede registrada con su respaldo, y que un equipo
 * cuyo cliente no quiso reparar tenga una salida en vez de quedar trabado.
 */
class RepairAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        Storage::fake(config('odessa.documents_disk'));
    }

    /** Recepción ya rechazada, que es donde empieza este circuito. */
    private function rechazada(?Organization $organizacion = null): Reception
    {
        $organizacion ??= $this->organization();

        $recepcion = Reception::create([
            'organization_id' => $organizacion->getKey(),
            'asset_id' => $this->asset($organizacion)->getKey(),
            'received_at' => now()->subDays(5)->toDateString(),
        ]);

        $recepcion->forceFill([
            'started_at' => now()->subDays(2),
            'released_at' => now()->subDay(),
            'outcome' => Reception::OUTCOME_REJECTED,
            'outcome_notes' => 'No pasó la prueba de presión.',
        ])->save();

        return $recepcion;
    }

    public function test_consultar_deja_la_recepcion_esperando_al_cliente(): void
    {
        $recepcion = $this->rechazada();

        $this->actingAs($this->staffUser(Permissions::ROLE_WORKSHOP))
            ->post("/taller/recepciones/{$recepcion->public_id}/consultar-reparacion", [
                'notes' => 'Cambio de sello: 4 horas de taller.',
            ])
            ->assertRedirect();

        $fresca = $recepcion->fresh();
        $this->assertSame('awaiting_client', $fresca->stage());
        $this->assertSame('Cambio de sello: 4 horas de taller.', $fresca->repair_request_notes);
        $this->assertDatabaseHas('activity_logs', ['action' => 'reception.repair_requested']);
    }

    /**
     * Sin aviso, la consulta se pierde: el equipo queda inmovilizado
     * esperando una respuesta que nadie sabe que tiene que dar, y la demora
     * se la come el cliente sin enterarse de que existe.
     *
     * Llega a toda la organización, no sólo a quien puede contestar: lo
     * pidió el negocio, y el de consulta es justamente quien audita.
     */
    public function test_se_avisa_a_toda_la_organizacion(): void
    {
        Notification::fake();

        $organizacion = $this->organization();
        $recepcion = $this->rechazada($organizacion);

        $operador = $this->clientUser($organizacion, Permissions::ROLE_CLIENT_OPERATOR);
        $consulta = $this->clientUser($organizacion, Permissions::ROLE_CLIENT_VIEWER);
        $ajeno = $this->clientUser($this->organization(), Permissions::ROLE_CLIENT_OPERATOR);

        $this->actingAs($this->staffUser(Permissions::ROLE_WORKSHOP))
            ->post("/taller/recepciones/{$recepcion->public_id}/consultar-reparacion", [
                'notes' => 'Cambio de sello.',
            ])
            ->assertRedirect();

        Notification::assertSentTo($operador, RepairAuthorizationRequested::class);
        Notification::assertSentTo($consulta, RepairAuthorizationRequested::class);
        Notification::assertNotSentTo($ajeno, RepairAuthorizationRequested::class);
    }

    public function test_no_se_consulta_dos_veces(): void
    {
        $recepcion = $this->rechazada();
        $usuario = $this->staffUser(Permissions::ROLE_WORKSHOP);

        $this->actingAs($usuario)
            ->post("/taller/recepciones/{$recepcion->public_id}/consultar-reparacion")
            ->assertRedirect();

        $this->actingAs($usuario)
            ->post("/taller/recepciones/{$recepcion->public_id}/consultar-reparacion")
            ->assertSessionHasErrors('repair_requested_at');
    }

    /**
     * El caso que antes no tenía salida: el cliente no autoriza, y el equipo
     * igual tiene que poder volver. Sale sin certificar, y eso se ve.
     */
    public function test_si_el_cliente_no_autoriza_el_equipo_se_devuelve_sin_reparar(): void
    {
        $recepcion = $this->rechazada();
        $usuario = $this->staffUser(Permissions::ROLE_WORKSHOP);

        $this->actingAs($usuario)
            ->post("/taller/recepciones/{$recepcion->public_id}/consultar-reparacion")
            ->assertRedirect();

        $this->actingAs($usuario)
            ->post("/taller/recepciones/{$recepcion->public_id}/autorizacion", [
                'decision' => 'declined',
                'channel' => 'phone',
                'notes' => 'El cliente lo da de baja: no justifica el arreglo.',
                'evidence' => UploadedFile::fake()->image('acta.jpg'),
            ])
            ->assertRedirect();

        $this->assertSame('repair_declined', $recepcion->fresh()->stage());

        // Y ahora sí puede salir, cosa que antes era imposible.
        $this->actingAs($usuario)
            ->post("/taller/recepciones/{$recepcion->public_id}/despachar")
            ->assertRedirect();

        $this->assertSame('returned_unrepaired', $recepcion->fresh()->stage());
    }

    public function test_no_autorizar_exige_motivo(): void
    {
        $recepcion = $this->rechazada();
        $usuario = $this->staffUser(Permissions::ROLE_WORKSHOP);

        $this->actingAs($usuario)
            ->post("/taller/recepciones/{$recepcion->public_id}/consultar-reparacion")
            ->assertRedirect();

        $this->actingAs($usuario)
            ->post("/taller/recepciones/{$recepcion->public_id}/autorizacion", [
                'decision' => 'declined',
                'channel' => 'phone',
                'evidence' => UploadedFile::fake()->image('acta.jpg'),
            ])
            ->assertSessionHasErrors('notes');

        $this->assertNull($recepcion->fresh()->repair_decision);
    }

    /**
     * Si Odessa transcribe la respuesta, tiene que poder respaldarla: es lo
     * que después justifica el cobro de la reparación.
     */
    public function test_odessa_debe_adjuntar_evidencia_de_la_respuesta(): void
    {
        $recepcion = $this->rechazada();
        $usuario = $this->staffUser(Permissions::ROLE_WORKSHOP);

        $this->actingAs($usuario)
            ->post("/taller/recepciones/{$recepcion->public_id}/consultar-reparacion")
            ->assertRedirect();

        $this->actingAs($usuario)
            ->post("/taller/recepciones/{$recepcion->public_id}/autorizacion", [
                'decision' => 'approved',
                'channel' => 'whatsapp',
            ])
            ->assertSessionHasErrors('evidence');
    }

    public function test_la_evidencia_queda_adjunta_a_la_recepcion(): void
    {
        $recepcion = $this->rechazada();
        $usuario = $this->staffUser(Permissions::ROLE_WORKSHOP);

        $this->actingAs($usuario)
            ->post("/taller/recepciones/{$recepcion->public_id}/consultar-reparacion")
            ->assertRedirect();

        $this->actingAs($usuario)
            ->post("/taller/recepciones/{$recepcion->public_id}/autorizacion", [
                'decision' => 'approved',
                'channel' => 'email',
                'evidence' => UploadedFile::fake()->image('correo.png'),
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('documents', [
            'documentable_type' => Reception::class,
            'documentable_id' => $recepcion->getKey(),
            'category' => 'repair_authorization',
        ]);

        $this->assertSame(1, $recepcion->fresh()->documents()->count());
    }

    /**
     * El canal no se elige, se deduce. Si el taller pudiera declarar que la
     * respuesta vino "del panel del cliente", se saltearía la evidencia y el
     * registro dejaría de valer para respaldar un cobro.
     */
    public function test_odessa_no_puede_declarar_que_la_respuesta_vino_del_panel(): void
    {
        $recepcion = $this->rechazada();
        $usuario = $this->staffUser(Permissions::ROLE_WORKSHOP);

        $this->actingAs($usuario)
            ->post("/taller/recepciones/{$recepcion->public_id}/consultar-reparacion")
            ->assertRedirect();

        $this->actingAs($usuario)
            ->post("/taller/recepciones/{$recepcion->public_id}/autorizacion", [
                'decision' => 'approved',
                'channel' => 'panel',
            ])
            ->assertSessionHasErrors('channel');
    }

    /** El cliente contesta desde su panel: ahí el registro mismo es la evidencia. */
    public function test_el_cliente_contesta_desde_su_panel_sin_adjuntar_nada(): void
    {
        $organizacion = $this->organization();
        $recepcion = $this->rechazada($organizacion);

        $this->actingAs($this->staffUser(Permissions::ROLE_WORKSHOP))
            ->post("/taller/recepciones/{$recepcion->public_id}/consultar-reparacion")
            ->assertRedirect();

        $this->actingAs($this->clientUser($organizacion))
            ->post("/taller/recepciones/{$recepcion->public_id}/autorizacion", [
                'decision' => 'approved',
            ])
            ->assertRedirect();

        $fresca = $recepcion->fresh();
        // Autorizar no abre un estado aparte: la pieza vuelve a Pendiente y se
        // reinician los hitos del trabajo para re-trabajarla.
        $this->assertSame('pending', $fresca->stage());
        $this->assertNull($fresca->started_at);
        $this->assertNull($fresca->released_at);
        $this->assertNull($fresca->outcome);
        $this->assertSame('panel', $fresca->repair_decision_channel);
        $this->assertSame(Reception::REPAIR_APPROVED, $fresca->repair_decision);
        $this->assertSame(0, $fresca->documents()->count());
    }

    /**
     * El circuito completo de una reparación autorizada: vuelve a Pendiente,
     * el taller la re-trabaja y esta vez la aprueba y la despacha. Ya no hay
     * estados intermedios de reparación.
     */
    public function test_autorizada_vuelve_a_pendiente_se_re_trabaja_y_se_despacha(): void
    {
        $organizacion = $this->organization();
        $recepcion = $this->rechazada($organizacion);
        $usuario = $this->staffUser(Permissions::ROLE_WORKSHOP);

        $this->actingAs($usuario)
            ->post("/taller/recepciones/{$recepcion->public_id}/consultar-reparacion")
            ->assertRedirect();

        $this->actingAs($this->clientUser($organizacion))
            ->post("/taller/recepciones/{$recepcion->public_id}/autorizacion", ['decision' => 'approved'])
            ->assertRedirect();

        $this->assertSame('pending', $recepcion->fresh()->stage());

        // Se re-trabaja como cualquier pieza pendiente.
        $this->actingAs($usuario)->post("/taller/recepciones/{$recepcion->public_id}/empezar")->assertRedirect();
        $this->actingAs($usuario)
            ->post("/taller/recepciones/{$recepcion->public_id}/dictamen", ['outcome' => 'approved'])
            ->assertRedirect();
        $this->assertSame('approved', $recepcion->fresh()->stage());

        $this->actingAs($usuario)->post("/taller/recepciones/{$recepcion->public_id}/despachar")->assertRedirect();
        $this->assertSame('dispatched', $recepcion->fresh()->stage());
    }

    /**
     * Se consulta una sola vez: si la reparación autorizada se re-trabaja y se
     * vuelve a rechazar, no hay segunda consulta; la única salida es "sin
     * solución".
     */
    public function test_una_autorizada_re_rechazada_solo_puede_marcarse_sin_solucion(): void
    {
        $organizacion = $this->organization();
        $recepcion = $this->rechazada($organizacion);
        $usuario = $this->staffUser(Permissions::ROLE_WORKSHOP);

        $this->actingAs($usuario)->post("/taller/recepciones/{$recepcion->public_id}/consultar-reparacion")->assertRedirect();
        $this->actingAs($this->clientUser($organizacion))
            ->post("/taller/recepciones/{$recepcion->public_id}/autorizacion", ['decision' => 'approved'])
            ->assertRedirect();

        // Re-trabajada y re-rechazada.
        $this->actingAs($usuario)->post("/taller/recepciones/{$recepcion->public_id}/empezar")->assertRedirect();
        $this->actingAs($usuario)
            ->post("/taller/recepciones/{$recepcion->public_id}/dictamen", ['outcome' => 'rejected', 'outcome_notes' => 'Sigue perdiendo.'])
            ->assertRedirect();
        $this->assertSame('rejected', $recepcion->fresh()->stage());

        // No hay segunda consulta.
        $this->actingAs($usuario)
            ->post("/taller/recepciones/{$recepcion->public_id}/consultar-reparacion")
            ->assertSessionHasErrors('repair_requested_at');

        // Sí queda "sin solución".
        $this->actingAs($usuario)
            ->post("/taller/recepciones/{$recepcion->public_id}/sin-solucion", ['unrepairable_notes' => 'No tiene arreglo.'])
            ->assertRedirect();
        $this->assertSame('unrepairable', $recepcion->fresh()->stage());
    }

    public function test_el_cliente_de_consulta_no_puede_autorizar(): void
    {
        $organizacion = $this->organization();
        $recepcion = $this->rechazada($organizacion);

        $this->actingAs($this->staffUser(Permissions::ROLE_WORKSHOP))
            ->post("/taller/recepciones/{$recepcion->public_id}/consultar-reparacion")
            ->assertRedirect();

        $this->actingAs($this->clientUser($organizacion, Permissions::ROLE_CLIENT_VIEWER))
            ->post("/taller/recepciones/{$recepcion->public_id}/autorizacion", [
                'decision' => 'approved',
            ])
            ->assertForbidden();
    }

    /** Aislamiento entre clientes: ni siquiera se entera de que existe. */
    public function test_un_cliente_ajeno_no_alcanza_la_recepcion(): void
    {
        $recepcion = $this->rechazada();

        $this->actingAs($this->staffUser(Permissions::ROLE_WORKSHOP))
            ->post("/taller/recepciones/{$recepcion->public_id}/consultar-reparacion")
            ->assertRedirect();

        $this->actingAs($this->clientUser($this->organization()))
            ->post("/taller/recepciones/{$recepcion->public_id}/autorizacion", [
                'decision' => 'approved',
            ])
            ->assertNotFound();
    }

    /** Fuera de la etapa de consulta no hay nada que contestar. */
    public function test_no_se_contesta_una_consulta_que_no_se_hizo(): void
    {
        $organizacion = $this->organization();
        $recepcion = $this->rechazada($organizacion);

        $this->actingAs($this->clientUser($organizacion))
            ->post("/taller/recepciones/{$recepcion->public_id}/autorizacion", [
                'decision' => 'approved',
            ])
            ->assertForbidden();
    }

    public function test_el_cliente_no_contesta_dos_veces(): void
    {
        $organizacion = $this->organization();
        $recepcion = $this->rechazada($organizacion);
        $cliente = $this->clientUser($organizacion);

        $this->actingAs($this->staffUser(Permissions::ROLE_WORKSHOP))
            ->post("/taller/recepciones/{$recepcion->public_id}/consultar-reparacion")
            ->assertRedirect();

        $this->actingAs($cliente)
            ->post("/taller/recepciones/{$recepcion->public_id}/autorizacion", ['decision' => 'approved'])
            ->assertRedirect();

        // La segunda vez ya no está esperando respuesta: el Policy lo corta.
        $this->actingAs($cliente)
            ->post("/taller/recepciones/{$recepcion->public_id}/autorizacion", ['decision' => 'declined'])
            ->assertForbidden();

        $this->assertSame(Reception::REPAIR_APPROVED, $recepcion->fresh()->repair_decision);
    }

    /**
     * Lo definió el negocio textual: el equipo se devuelve «marcado como No
     * Apto». Si volviera figurando certificado, el sistema estaría diciendo
     * exactamente lo contrario de lo que pasó, y el cliente podría mandarlo a
     * un pozo confiando en una certificación que ya no corresponde.
     */
    public function test_el_equipo_devuelto_sin_reparar_queda_no_apto(): void
    {
        $organizacion = $this->organization();
        $recepcion = $this->rechazada($organizacion);
        $activo = $recepcion->asset;
        $usuario = $this->staffUser(Permissions::ROLE_WORKSHOP);

        // Parte de un equipo certificado y con vencimiento futuro: es el caso
        // en el que el hueco se notaba.
        $activo->forceFill([
            'status' => AssetStatus::Fit,
            'expires_at' => now()->addYear(),
        ])->save();

        $this->actingAs($usuario)
            ->post("/taller/recepciones/{$recepcion->public_id}/consultar-reparacion")
            ->assertRedirect();

        $this->actingAs($usuario)
            ->post("/taller/recepciones/{$recepcion->public_id}/autorizacion", [
                'decision' => 'declined',
                'channel' => 'phone',
                'notes' => 'No justifica el arreglo.',
                'evidence' => UploadedFile::fake()->image('acta.jpg'),
            ])
            ->assertRedirect();

        $activo->refresh();

        $this->assertSame(AssetStatus::Unfit, $activo->status);
        // Y sin vencimiento: dejarlo sería seguir prometiendo una vigencia.
        $this->assertNull($activo->expires_at);
        $this->assertNull($activo->send_by_at);
    }

    /**
     * El rechazo no condena la pieza para siempre: si el cliente la vuelve a
     * mandar y esta vez aprueba, manda el dictamen nuevo.
     */
    public function test_una_inspeccion_posterior_favorable_devuelve_la_aptitud(): void
    {
        $organizacion = $this->organization();
        $recepcion = $this->rechazada($organizacion);
        $activo = $recepcion->asset;
        $usuario = $this->staffUser(Permissions::ROLE_WORKSHOP);

        $this->actingAs($usuario)
            ->post("/taller/recepciones/{$recepcion->public_id}/consultar-reparacion")
            ->assertRedirect();

        $this->actingAs($usuario)
            ->post("/taller/recepciones/{$recepcion->public_id}/autorizacion", [
                'decision' => 'declined',
                'channel' => 'phone',
                'notes' => 'Por ahora no.',
                'evidence' => UploadedFile::fake()->image('acta.jpg'),
            ])
            ->assertRedirect();

        $this->assertSame(AssetStatus::Unfit, $activo->fresh()->status);

        // Vuelve más adelante y esta vez pasa.
        $this->reportFor(
            $activo,
            InspectionResult::Apto,
            now()->addDay()->toDateString(),
            now()->addYear()->toDateString(),
        );

        app(RecalculateAssetStatus::class)($activo);

        $this->assertSame(AssetStatus::Fit, $activo->fresh()->status);
    }

    /**
     * El hilo de trazabilidad no puede tener un hueco justo donde está la
     * decisión que se cobra: tiene que decir qué se propuso, qué contestó el
     * cliente, por qué vía, y que el equipo volvió sin reparar.
     */
    public function test_el_hilo_del_equipo_muestra_la_consulta_y_la_respuesta(): void
    {
        $organizacion = $this->organization();
        $recepcion = $this->rechazada($organizacion);
        $usuario = $this->staffUser(Permissions::ROLE_WORKSHOP);

        $this->actingAs($usuario)
            ->post("/taller/recepciones/{$recepcion->public_id}/consultar-reparacion", [
                'notes' => 'Cambio de sello: USD 400.',
            ])
            ->assertRedirect();

        $this->actingAs($usuario)
            ->post("/taller/recepciones/{$recepcion->public_id}/autorizacion", [
                'decision' => 'declined',
                'channel' => 'phone',
                'notes' => 'No justifica el arreglo.',
                'evidence' => UploadedFile::fake()->image('acta.jpg'),
            ])
            ->assertRedirect();

        $this->actingAs($usuario)
            ->post("/taller/recepciones/{$recepcion->public_id}/despachar")
            ->assertRedirect();

        $hilo = collect((new AssetTimeline($recepcion->asset->fresh()))->build());

        $this->assertTrue($hilo->contains('title', 'Se consultó al cliente si autoriza la reparación'));
        $this->assertTrue($hilo->contains('title', 'El cliente no autorizó la reparación'));
        // El final tiene que distinguirse de un despacho normal: el equipo se
        // fue sin certificar y eso no puede leerse como un cierre feliz.
        $this->assertTrue($hilo->contains('title', 'Devuelto al cliente sin reparar'));
        $this->assertFalse($hilo->contains('title', 'Despachado al cliente'));

        $decision = $hilo->firstWhere('title', 'El cliente no autorizó la reparación');
        $this->assertStringContainsString('Vía: Teléfono', (string) $decision['detail']);
        $this->assertStringContainsString('No justifica el arreglo.', (string) $decision['detail']);
        $this->assertSame($usuario->name, $decision['by']);
    }

    /** El documento de respaldo no puede quedar suelto de su organización. */
    public function test_la_evidencia_hereda_la_organizacion_de_la_recepcion(): void
    {
        $organizacion = $this->organization();
        $recepcion = $this->rechazada($organizacion);
        $usuario = $this->staffUser(Permissions::ROLE_WORKSHOP);

        $this->actingAs($usuario)
            ->post("/taller/recepciones/{$recepcion->public_id}/consultar-reparacion")
            ->assertRedirect();

        $this->actingAs($usuario)
            ->post("/taller/recepciones/{$recepcion->public_id}/autorizacion", [
                'decision' => 'approved',
                'channel' => 'email',
                'evidence' => UploadedFile::fake()->image('correo.png'),
            ])
            ->assertRedirect();

        $documento = Document::withoutGlobalScopes()
            ->where('documentable_id', $recepcion->getKey())
            ->firstOrFail();

        $this->assertSame($organizacion->getKey(), $documento->organization_id);
    }
}
