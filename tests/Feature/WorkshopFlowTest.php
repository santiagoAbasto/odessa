<?php

namespace Tests\Feature;

use App\Enums\AssetStatus;
use App\Models\Organization;
use App\Models\Reception;
use App\Models\Shipment;
use App\Notifications\ReceptionDeclaredUnrepairable;
use App\Notifications\RepairAuthorizationRequested;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Recorrido del taller tal como lo redefinió el negocio (revisado el 28/09/2026):
 *
 *   Pendientes → En proceso → Aprobar → Listas para despachar
 *                    ↓ Rechazar
 *               Rechazadas ─ Consultar → Esperando cliente ─ SÍ → vuelve a Pendientes (se re-trabaja)
 *                    └─ Sin solución → Listas para despachar (no apto)
 *
 * Al autorizar la reparación no hay un estado aparte: el equipo vuelve a
 * Pendientes y se re-trabaja. Se consulta una sola vez: si se lo re-rechaza,
 * la única salida es "sin solución".
 */
class WorkshopFlowTest extends TestCase
{
    use RefreshDatabase;

    private const ETAPAS = [
        'pending', 'in_progress', 'approved', 'rejected', 'awaiting_client',
        'repair_declined', 'unrepairable',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    private function recepcion(array $hitos = [], ?Organization $org = null): Reception
    {
        $org ??= $this->organization();

        $recepcion = Reception::create([
            'organization_id' => $org->getKey(),
            'asset_id' => $this->asset($org)->getKey(),
            'received_at' => now()->subDays(5)->toDateString(),
        ]);

        $recepcion->forceFill($hitos)->save();

        return $recepcion->fresh();
    }

    /** Hitos acumulados que dejan una recepción en la etapa pedida. */
    private function hitos(string $etapa): array
    {
        $rechazada = [
            'started_at' => now()->subDays(4),
            'released_at' => now()->subDays(3),
            'outcome' => Reception::OUTCOME_REJECTED,
            'outcome_notes' => 'Rosca gastada.',
        ];
        $consultada = $rechazada + ['repair_requested_at' => now()->subDays(3)];

        return match ($etapa) {
            'pending' => [],
            'in_progress' => ['started_at' => now()->subDay()],
            'approved' => ['started_at' => now()->subDays(2), 'released_at' => now()->subDay(), 'outcome' => Reception::OUTCOME_APPROVED],
            'rejected' => $rechazada,
            'awaiting_client' => $consultada,
            'repair_declined' => $consultada + [
                'repair_decision' => Reception::REPAIR_DECLINED,
                'repair_decided_at' => now()->subDays(2),
                'repair_decision_notes' => 'No justifica el arreglo.',
            ],
            'unrepairable' => $rechazada + ['unrepairable_at' => now(), 'unrepairable_notes' => 'Cuerpo fisurado.'],
        };
    }

    /**
     * Reproduce una reparación ya autorizada: se consultó y el cliente dijo
     * que sí, así que los hitos del trabajo se reiniciaron y la pieza volvió a
     * Pendiente. Queda constancia de la consulta para no volver a preguntar.
     */
    private function autorizadaVueltaAPendiente(): array
    {
        return [
            'repair_requested_at' => now()->subDays(3),
            'repair_decision' => Reception::REPAIR_APPROVED,
            'repair_decided_at' => now()->subDays(2),
        ];
    }

    /** Ids públicos que muestra una pestaña del taller. */
    private function pestaña(string $stage): Collection
    {
        return collect(
            $this->get("/taller/recepciones?stage={$stage}")->viewData('page')['props']['receptions']['data']
        )->pluck('id');
    }

    // --- Etapas y colas ------------------------------------------------------

    public function test_cada_combinacion_de_hitos_da_su_etapa(): void
    {
        foreach (self::ETAPAS as $etapa) {
            $this->assertSame($etapa, $this->recepcion($this->hitos($etapa))->stage(), $etapa);
        }

        $devuelta = $this->recepcion($this->hitos('unrepairable') + ['dispatched_at' => now()]);
        $this->assertSame('returned_unrepaired', $devuelta->stage());
    }

    /** Una reparación autorizada, con el trabajo reiniciado, deriva a Pendiente. */
    public function test_la_reparacion_autorizada_vuelve_a_pendiente(): void
    {
        $r = $this->recepcion($this->autorizadaVueltaAPendiente());
        $this->assertSame('pending', $r->stage());
    }

    public function test_las_colas_agrupan_segun_el_recorrido_nuevo(): void
    {
        $r = fn (string $etapa) => $this->recepcion($this->hitos($etapa));
        $ids = fn (string $scope) => Reception::query()->{$scope}()->pluck('id')->all();

        $enProceso = $r('in_progress');
        $rechazada = $r('rejected');
        $esperando = $r('awaiting_client');
        $declinada = $r('repair_declined');
        $sinSolucion = $r('unrepairable');
        $vueltaPendiente = $this->recepcion($this->autorizadaVueltaAPendiente());

        // La autorizada se re-trabaja como cualquier otra: no está en proceso hasta empezar.
        $this->assertEqualsCanonicalizing([$enProceso->id], $ids('inProgress'));
        // Lo consultado y lo declinado salen de Rechazadas.
        $this->assertEqualsCanonicalizing([$rechazada->id], $ids('rejected'));
        $this->assertEqualsCanonicalizing([$esperando->id], $ids('awaitingClient'));
        // La declinada y la sin solución ya están para despachar.
        $this->assertContains($declinada->id, $ids('readyToDispatch'));
        $this->assertContains($sinSolucion->id, $ids('readyToDispatch'));
        // La vuelta a pendiente todavía no puede despacharse: falta re-trabajarla.
        $this->assertNotContains($vueltaPendiente->id, $ids('readyToDispatch'));
    }

    /**
     * Si divergen, la lista de "Listas para despachar" muestra filas cuyo
     * botón de despachar da error, o esconde piezas que sí pueden salir.
     */
    public function test_despachable_y_listas_para_despachar_coinciden_en_todas_las_etapas(): void
    {
        foreach (self::ETAPAS as $etapa) {
            $r = $this->recepcion($this->hitos($etapa));

            $this->assertSame(
                $r->isDispatchable(),
                Reception::query()->readyToDispatch()->whereKey($r->getKey())->exists(),
                $etapa,
            );
        }

        // Liberada sin dictamen registrado: en SQL, NULL != 'rejected' no es
        // verdadero, y la lista la dejaba afuera aunque se pudiera despachar.
        $sinDictamen = $this->recepcion(['started_at' => now()->subDay(), 'released_at' => now(), 'outcome' => null]);
        $this->assertTrue($sinDictamen->isDispatchable());
        $this->assertTrue(Reception::query()->readyToDispatch()->whereKey($sinDictamen->getKey())->exists());
    }

    // --- Acciones ------------------------------------------------------------

    public function test_autorizar_devuelve_la_pieza_a_pendientes_y_se_re_trabaja(): void
    {
        Notification::fake();
        $org = $this->organization();
        $r = $this->recepcion($this->hitos('awaiting_client'), $org);
        $this->actingAs($this->staffUser(Permissions::ROLE_WORKSHOP));

        $this->assertTrue($this->pestaña('awaiting_client')->contains($r->public_id));

        $this->post("/taller/recepciones/{$r->public_id}/autorizacion", [
            'decision' => 'approved',
            'channel' => 'phone',
            'evidence' => UploadedFile::fake()->image('ok.jpg'),
        ])->assertRedirect();

        // Vuelve a Pendientes y sale de Esperando al cliente.
        $this->assertTrue($this->pestaña('pending')->contains($r->public_id));
        $this->assertFalse($this->pestaña('awaiting_client')->contains($r->public_id));

        // Se re-trabaja y queda lista para despachar.
        $this->post("/taller/recepciones/{$r->public_id}/empezar")->assertRedirect();
        $this->post("/taller/recepciones/{$r->public_id}/dictamen", ['outcome' => 'approved'])->assertRedirect();
        $this->assertTrue($this->pestaña('ready')->contains($r->public_id));
    }

    public function test_sin_solucion_directo_desde_el_primer_rechazo_exige_motivo(): void
    {
        Notification::fake();
        $r = $this->recepcion($this->hitos('rejected'));
        $this->actingAs($this->staffUser(Permissions::ROLE_WORKSHOP));

        $this->post("/taller/recepciones/{$r->public_id}/sin-solucion")
            ->assertSessionHasErrors('unrepairable_notes');

        $this->post("/taller/recepciones/{$r->public_id}/sin-solucion", [
            'unrepairable_notes' => 'No hay repuesto.',
        ])->assertRedirect();

        $this->assertSame('unrepairable', $r->fresh()->stage());
        $this->assertTrue($this->pestaña('ready')->contains($r->public_id));
        $this->assertDatabaseHas('activity_logs', ['action' => 'reception.unrepairable']);
    }

    public function test_sin_solucion_y_devolucion_dejan_el_activo_no_apto(): void
    {
        Notification::fake();
        $r = $this->recepcion($this->hitos('rejected'));
        $r->asset->forceFill(['status' => AssetStatus::Fit, 'expires_at' => now()->addYear()])->save();
        $this->actingAs($this->staffUser(Permissions::ROLE_WORKSHOP));

        $this->post("/taller/recepciones/{$r->public_id}/sin-solucion", [
            'unrepairable_notes' => 'Cuerpo fisurado.',
        ])->assertRedirect();
        $this->post("/taller/recepciones/{$r->public_id}/despachar")->assertRedirect();

        $this->assertSame('returned_unrepaired', $r->fresh()->stage());

        $activo = $r->asset->fresh();
        $this->assertSame(AssetStatus::Unfit, $activo->status);
        $this->assertNull($activo->expires_at);
        $this->assertNull($activo->send_by_at);
    }

    public function test_sin_solucion_no_se_marca_fuera_de_rechazadas(): void
    {
        $this->actingAs($this->staffUser(Permissions::ROLE_WORKSHOP));

        foreach (['awaiting_client', 'approved', 'in_progress'] as $etapa) {
            $r = $this->recepcion($this->hitos($etapa));

            $this->post("/taller/recepciones/{$r->public_id}/sin-solucion", ['unrepairable_notes' => 'x'])
                ->assertSessionHasErrors('unrepairable_at');

            $this->assertNull($r->fresh()->unrepairable_at, $etapa);
        }
    }

    public function test_un_cliente_no_puede_marcar_sin_solucion(): void
    {
        $org = $this->organization();
        $r = $this->recepcion($this->hitos('rejected'), $org);

        $this->actingAs($this->clientUser($org))
            ->post("/taller/recepciones/{$r->public_id}/sin-solucion", ['unrepairable_notes' => 'x'])
            ->assertForbidden();
    }

    // --- Avisos --------------------------------------------------------------

    public function test_la_consulta_y_el_sin_solucion_llegan_a_toda_la_organizacion(): void
    {
        Notification::fake();
        $org = $this->organization();
        $org->forceFill(['contact_email' => 'compras@cliente.test'])->save();
        $operador = $this->clientUser($org, Permissions::ROLE_CLIENT_OPERATOR);
        $consulta = $this->clientUser($org, Permissions::ROLE_CLIENT_VIEWER);
        $ajeno = $this->clientUser($this->organization());
        $this->actingAs($this->staffUser(Permissions::ROLE_WORKSHOP));

        $r = $this->recepcion($this->hitos('rejected'), $org);
        $this->post("/taller/recepciones/{$r->public_id}/consultar-reparacion")->assertRedirect();

        Notification::assertSentTo([$operador, $consulta], RepairAuthorizationRequested::class);
        Notification::assertNotSentTo($ajeno, RepairAuthorizationRequested::class);
        Notification::assertSentOnDemand(
            RepairAuthorizationRequested::class,
            fn ($n, $channels, $notifiable) => $notifiable->routes['mail'] === 'compras@cliente.test',
        );

        $otra = $this->recepcion($this->hitos('rejected'), $org);
        $this->post("/taller/recepciones/{$otra->public_id}/sin-solucion", [
            'unrepairable_notes' => 'No hay repuesto.',
        ])->assertRedirect();

        Notification::assertSentTo([$operador, $consulta], ReceptionDeclaredUnrepairable::class);
        Notification::assertNotSentTo($ajeno, ReceptionDeclaredUnrepairable::class);
        Notification::assertSentOnDemand(ReceptionDeclaredUnrepairable::class);
    }

    public function test_los_avisos_llegan_a_todos_los_correos_de_contacto_de_la_organizacion(): void
    {
        Notification::fake();
        $org = $this->organization();
        // Varios correos, uno por línea, con un vacío y un repetido en otra caja.
        $org->forceFill(['contact_email' => "compras@cliente.test\noperaciones@cliente.test\n\nCOMPRAS@cliente.test"])->save();
        $this->actingAs($this->staffUser(Permissions::ROLE_WORKSHOP));

        $r = $this->recepcion($this->hitos('rejected'), $org);
        $this->post("/taller/recepciones/{$r->public_id}/consultar-reparacion")->assertRedirect();

        // Dos casillas distintas, sin contar el repetido.
        Notification::assertSentOnDemandTimes(RepairAuthorizationRequested::class, 2);
    }

    public function test_el_correo_de_contacto_no_se_duplica_si_ya_es_de_un_usuario(): void
    {
        Notification::fake();
        $org = $this->organization();
        $operador = $this->clientUser($org);
        $org->forceFill(['contact_email' => strtoupper($operador->email)])->save();

        $r = $this->recepcion($this->hitos('rejected'), $org);
        $this->actingAs($this->staffUser())
            ->post("/taller/recepciones/{$r->public_id}/consultar-reparacion")
            ->assertRedirect();

        Notification::assertSentTo($operador, RepairAuthorizationRequested::class);
        Notification::assertSentOnDemandTimes(RepairAuthorizationRequested::class, 0);
    }

    /** Sin fake: el destinatario anónimo no tiene canal `database`. */
    public function test_el_aviso_al_correo_de_contacto_no_rompe_el_canal_de_la_aplicacion(): void
    {
        $org = $this->organization();
        $org->forceFill(['contact_email' => 'compras@cliente.test'])->save();
        $operador = $this->clientUser($org);

        $r = $this->recepcion($this->hitos('rejected'), $org);
        $this->actingAs($this->staffUser())
            ->post("/taller/recepciones/{$r->public_id}/consultar-reparacion")
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(1, $operador->notifications()->count());
        $this->assertStringContainsString(
            "reception={$r->public_id}",
            $operador->notifications()->first()->data['url'],
        );
    }

    // --- Enlace directo ------------------------------------------------------

    public function test_el_enlace_directo_muestra_solo_esa_recepcion_y_respeta_la_organizacion(): void
    {
        $org = $this->organization();
        $propia = $this->recepcion($this->hitos('awaiting_client'), $org);
        $this->recepcion($this->hitos('awaiting_client'), $org);
        $ajena = $this->recepcion($this->hitos('awaiting_client'));

        $this->actingAs($this->clientUser($org));

        $ids = fn (string $id) => collect(
            $this->get("/taller/recepciones?stage=all&reception={$id}")->viewData('page')['props']['receptions']['data']
        )->pluck('id')->all();

        $this->assertSame([$propia->public_id], $ids($propia->public_id));
        $this->assertSame([], $ids($ajena->public_id));
    }

    // --- Envío ---------------------------------------------------------------

    public function test_el_envio_enviado_queda_pendiente_de_recepcion(): void
    {
        $envio = new Shipment;
        $envio->status = Shipment::STATUS_SUBMITTED;
        $this->assertSame('Pendiente de recepción', $envio->statusLabel());

        $envio->status = Shipment::STATUS_AUTHORIZED;
        $this->assertSame('Recibido', $envio->statusLabel());
    }
}
