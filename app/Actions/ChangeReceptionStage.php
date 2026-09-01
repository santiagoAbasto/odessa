<?php

namespace App\Actions;

use App\Models\Reception;
use App\Models\User;
use App\Notifications\RepairAuthorizationRequested;
use App\Support\ActivityLogger;
use App\Support\Permissions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Avanza una recepción por el pipeline del taller.
 *
 * Los hitos están ordenados, pero el recorrido no es una sola línea:
 *
 *   empezar → dictaminar ─┬─ aprobado ────────────────────────→ despachar
 *                         │
 *                         └─ rechazado → consultar al cliente ─┬─ SÍ → reparar → despachar
 *                                                              └─ NO → despachar sin reparar
 *
 * No se puede saltar un hito hacia atrás ni repetir uno ya cumplido. Y no se
 * puede despachar un equipo rechazado sobre el que nadie decidió nada: tiene
 * que estar reparado, o tener registrado que el cliente no quiso repararlo.
 */
class ChangeReceptionStage
{
    public function __construct(private RecalculateAssetStatus $recalculate) {}

    /**
     * Marca que el taller empezó a trabajar el equipo.
     *
     * Es lo que separa la cola de entrada del trabajo en curso. Se llama
     * también al liberar, porque liberar sin haber empezado es un contrasentido
     * y dejaría la recepción con un hueco en su propia historia.
     */
    public function start(Reception $reception): Reception
    {
        if ($reception->hasStarted()) {
            throw ValidationException::withMessages([
                'started_at' => 'El trabajo de esta recepción ya estaba empezado.',
            ]);
        }

        if ($reception->isDispatched()) {
            throw ValidationException::withMessages([
                'started_at' => 'Esta recepción ya fue despachada.',
            ]);
        }

        DB::transaction(function () use ($reception) {
            $reception->forceFill([
                'started_at' => now(),
                'started_by' => Auth::id(),
            ])->save();
        });

        ActivityLogger::log(
            action: 'reception.started',
            subject: $reception,
            description: 'Se empezó a trabajar el equipo',
            new: ['started_at' => $reception->started_at?->toIso8601String()],
        );

        return $reception;
    }

    /**
     * Dictamen del trabajo: aprobado o rechazado.
     *
     * Reemplaza al antiguo "liberar", que mezclaba las dos salidas y hacía que
     * un equipo rechazado se viera igual que uno aprobado. Un rechazo exige
     * motivo: es lo que después justifica la reparación.
     */
    public function resolve(Reception $reception, string $outcome, ?string $notes = null): Reception
    {
        if ($reception->isResolved()) {
            throw ValidationException::withMessages([
                'outcome' => 'Esta recepción ya tiene dictamen.',
            ]);
        }

        if (! in_array($outcome, [Reception::OUTCOME_APPROVED, Reception::OUTCOME_REJECTED], true)) {
            throw ValidationException::withMessages([
                'outcome' => 'El dictamen debe ser aprobar o rechazar.',
            ]);
        }

        if ($outcome === Reception::OUTCOME_REJECTED && blank($notes)) {
            throw ValidationException::withMessages([
                'outcome_notes' => 'Indique el motivo del rechazo.',
            ]);
        }

        DB::transaction(function () use ($reception, $outcome, $notes) {
            $reception->forceFill([
                // Resolver implica que se trabajó. Si nadie marcó el inicio
                // —pasa cuando el trabajo fue corto—, se registra ahora para
                // que la etapa "en proceso" no quede saltada en el historial.
                'started_at' => $reception->started_at ?? now(),
                'started_by' => $reception->started_by ?? Auth::id(),
                'released_at' => now(),
                'released_by' => Auth::id(),
                'outcome' => $outcome,
                'outcome_notes' => $notes,
            ])->save();
        });

        $aprobado = $outcome === Reception::OUTCOME_APPROVED;

        ActivityLogger::log(
            action: $aprobado ? 'reception.approved' : 'reception.rejected',
            subject: $reception,
            description: $aprobado ? 'Trabajo aprobado' : 'Trabajo rechazado',
            new: ['outcome' => $outcome, 'notes' => $notes],
        );

        return $reception;
    }

    /**
     * Le pide al cliente que decida si autoriza la reparación.
     *
     * Es el paso que faltaba. Antes el taller mandaba a reparar por su cuenta
     * y esa decisión no quedaba registrada en ningún lado, con dos problemas:
     * no había con qué respaldar el cobro, y una recepción rechazada que el
     * cliente no quería reparar se quedaba trabada sin salida posible.
     */
    public function requestRepairAuthorization(Reception $reception, ?string $notes = null): Reception
    {
        if (! $reception->wasRejected()) {
            throw ValidationException::withMessages([
                'repair_requested_at' => 'Sólo se consulta la reparación de lo que fue rechazado.',
            ]);
        }

        if ($reception->repairWasRequested()) {
            throw ValidationException::withMessages([
                'repair_requested_at' => 'Ya se le pidió al cliente que autorice esta reparación.',
            ]);
        }

        DB::transaction(function () use ($reception, $notes) {
            $reception->forceFill([
                'repair_requested_at' => now(),
                'repair_requested_by' => Auth::id(),
                'repair_request_notes' => $notes,
            ])->save();
        });

        ActivityLogger::log(
            action: 'reception.repair_requested',
            subject: $reception,
            description: 'Se le pidió al cliente que autorice la reparación',
            new: ['notes' => $notes],
        );

        $this->notifyClientOfRepairRequest($reception);

        return $reception;
    }

    /**
     * Avisa a quien puede contestar.
     *
     * Sin esto la consulta se pierde: el equipo queda inmovilizado esperando
     * una respuesta que nadie sabe que tiene que dar. Se avisa sólo a los
     * usuarios que efectivamente pueden decidir —el rol de consulta no
     * puede—, para que el aviso no llegue a quien no tiene el botón.
     *
     * Va fuera de la transacción a propósito: si el correo falla, la consulta
     * igual quedó registrada. Perder el aviso es molesto; perder el hito
     * sería peor.
     */
    private function notifyClientOfRepairRequest(Reception $reception): void
    {
        $recipients = User::withoutGlobalScope('organization')
            ->where('organization_id', $reception->organization_id)
            ->where('is_active', true)
            ->get()
            ->filter(fn (User $u) => $u->can(Permissions::RECEPTIONS_AUTHORIZE_REPAIR));

        if ($recipients->isEmpty()) {
            return;
        }

        Notification::send($recipients, new RepairAuthorizationRequested($reception));
    }

    /**
     * Registra la respuesta del cliente.
     *
     * Puede llegar por dos vías, y las dos son válidas: el cliente la carga
     * desde su panel, o alguien de Odessa transcribe lo que recibió por
     * teléfono o correo. Por eso se guarda el canal —`panel` es la única que
     * el sistema puede dar por cierta por sí misma— y se admite adjuntar
     * evidencia, que es lo que después respalda el cobro de la reparación.
     */
    public function recordRepairDecision(
        Reception $reception,
        string $decision,
        string $channel,
        ?string $notes = null,
    ): Reception {
        if (! $reception->repairWasRequested()) {
            throw ValidationException::withMessages([
                'repair_decision' => 'Todavía no se le pidió al cliente que autorice esta reparación.',
            ]);
        }

        if ($reception->repair_decision !== null) {
            throw ValidationException::withMessages([
                'repair_decision' => 'El cliente ya contestó sobre esta reparación.',
            ]);
        }

        if (! in_array($decision, [Reception::REPAIR_APPROVED, Reception::REPAIR_DECLINED], true)) {
            throw ValidationException::withMessages([
                'repair_decision' => 'La respuesta debe ser autorizar o no autorizar.',
            ]);
        }

        if (! array_key_exists($channel, Reception::DECISION_CHANNELS)) {
            throw ValidationException::withMessages([
                'repair_decision_channel' => 'Indique por qué vía llegó la respuesta.',
            ]);
        }

        /*
         * No autorizar tiene consecuencias: el equipo se le devuelve al cliente
         * sin certificar. Exigir el motivo es lo mínimo para que después se
         * entienda por qué salió así.
         */
        if ($decision === Reception::REPAIR_DECLINED && blank($notes)) {
            throw ValidationException::withMessages([
                'repair_decision_notes' => 'Indique por qué no se autoriza la reparación.',
            ]);
        }

        DB::transaction(function () use ($reception, $decision, $channel, $notes) {
            $reception->forceFill([
                'repair_decision' => $decision,
                'repair_decided_at' => now(),
                'repair_decided_by' => Auth::id(),
                'repair_decision_channel' => $channel,
                'repair_decision_notes' => $notes,
            ])->save();

            /*
             * No autorizar cambia la aptitud del equipo: vuelve al cliente sin
             * arreglar, así que no puede seguir figurando certificado. El
             * estado no se escribe acá —se recalcula—, porque la aptitud
             * siempre se deriva de los hechos y nunca se asigna a mano.
             */
            if ($decision === Reception::REPAIR_DECLINED && $reception->asset !== null) {
                ($this->recalculate)($reception->asset);
            }
        });

        $autoriza = $decision === Reception::REPAIR_APPROVED;

        ActivityLogger::log(
            action: $autoriza ? 'reception.repair_approved' : 'reception.repair_declined',
            subject: $reception,
            description: $autoriza
                ? 'El cliente autorizó la reparación'
                : 'El cliente no autorizó la reparación',
            new: ['channel' => $channel, 'notes' => $notes],
        );

        return $reception;
    }

    /**
     * El taller empieza la reparación que el cliente autorizó.
     *
     * Se mantiene como paso propio y no se dispara solo al autorizar, porque
     * entre una cosa y la otra suele pasar tiempo: saber cuánto es justamente
     * lo que permite medir la demora del taller aparte de la del cliente.
     */
    public function sendToRepair(Reception $reception): Reception
    {
        if (! $reception->wasRejected()) {
            throw ValidationException::withMessages([
                'repair_started_at' => 'Sólo se manda a reparar lo que fue rechazado.',
            ]);
        }

        if (! $reception->repairWasApproved()) {
            throw ValidationException::withMessages([
                'repair_started_at' => $reception->repairWasDeclined()
                    ? 'El cliente no autorizó esta reparación.'
                    : 'Falta que el cliente autorice la reparación.',
            ]);
        }

        if ($reception->repair_started_at !== null) {
            throw ValidationException::withMessages([
                'repair_started_at' => 'Esta recepción ya está en reparación.',
            ]);
        }

        DB::transaction(function () use ($reception) {
            $reception->forceFill([
                'repair_started_at' => now(),
                'repair_started_by' => Auth::id(),
            ])->save();
        });

        ActivityLogger::log(
            action: 'reception.sent_to_repair',
            subject: $reception,
            description: 'Enviada a reparación tras el rechazo',
        );

        return $reception;
    }

    /** Reparación terminada: el equipo vuelve a poder despacharse. */
    public function markRepaired(Reception $reception): Reception
    {
        if ($reception->repair_started_at === null) {
            throw ValidationException::withMessages([
                'repaired_at' => 'Esta recepción no está en reparación.',
            ]);
        }

        if ($reception->repaired_at !== null) {
            throw ValidationException::withMessages([
                'repaired_at' => 'Esta reparación ya se dio por terminada.',
            ]);
        }

        DB::transaction(function () use ($reception) {
            $reception->forceFill([
                'repaired_at' => now(),
                'repaired_by' => Auth::id(),
            ])->save();
        });

        ActivityLogger::log(
            action: 'reception.repaired',
            subject: $reception,
            description: 'Reparación terminada',
        );

        return $reception;
    }

    public function dispatch(Reception $reception, ?string $note = null): Reception
    {
        if ($reception->isDispatched()) {
            throw ValidationException::withMessages([
                'dispatched_at' => 'Esta recepción ya fue despachada.',
            ]);
        }

        /*
         * Lo que se impide no es despachar algo no apto —eso es legítimo si el
         * cliente decidió no repararlo— sino despacharlo sin que nadie haya
         * decidido nada.
         */
        if (! $reception->isDispatchable()) {
            throw ValidationException::withMessages([
                'dispatched_at' => match (true) {
                    ! $reception->wasRejected() => 'La pieza debe tener dictamen antes de despacharse.',
                    ! $reception->repairWasRequested() => 'La pieza fue rechazada: consulte al cliente si autoriza la reparación.',
                    $reception->repairWasApproved() => 'El cliente autorizó la reparación: debe completarse antes de despachar.',
                    default => 'Falta que el cliente conteste si autoriza la reparación.',
                },
            ]);
        }

        DB::transaction(function () use ($reception, $note) {
            $reception->forceFill([
                'dispatched_at' => now(),
                'dispatched_by' => Auth::id(),
                'dispatch_note' => $note,
            ])->save();
        });

        ActivityLogger::log(
            action: 'reception.dispatched',
            subject: $reception,
            description: 'Recepción despachada',
            new: ['dispatched_at' => $reception->dispatched_at?->toIso8601String()],
        );

        return $reception;
    }
}
