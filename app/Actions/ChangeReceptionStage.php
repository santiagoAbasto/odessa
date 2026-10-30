<?php

namespace App\Actions;

use App\Models\Organization;
use App\Models\Reception;
use App\Models\User;
use App\Notifications\ReceptionDeclaredUnrepairable;
use App\Notifications\RepairAuthorizationRequested;
use App\Support\ActivityLogger;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification as Notifier;
use Illuminate\Validation\ValidationException;

/**
 * Avanza una recepción por el pipeline del taller.
 *
 * Los hitos están ordenados, pero el recorrido no es una sola línea:
 *
 *   empezar → dictaminar ─┬─ aprobado ─────────────────────────────→ despachar
 *                         │
 *                         └─ rechazado ─┬─ consultar al cliente ─┬─ SÍ → vuelve a Pendiente (se re-trabaja)
 *                                       │                        └─ NO → despachar sin reparar
 *                                       └─ sin solución → despachar sin reparar
 *
 * No se puede saltar un hito hacia atrás ni repetir uno ya cumplido. Al
 * autorizar la reparación no hay un estado aparte: el equipo vuelve a
 * Pendiente y se re-trabaja. Se consulta una sola vez. Y no se puede despachar
 * un equipo rechazado sobre el que nadie decidió nada.
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
     * Deshace un dictamen cargado por error (aprobado o rechazado) y devuelve
     * la recepción a "en proceso". Sólo mientras no haya seguido su curso: si
     * ya se consultó al cliente, se marcó sin solución o se despachó, no se
     * toca. Queda en la auditoría con quién lo deshizo y por qué.
     */
    public function revertResolution(Reception $reception, ?string $reason = null): Reception
    {
        if (! $reception->isResolved()) {
            throw ValidationException::withMessages([
                'outcome' => 'Esta recepción no tiene un dictamen que deshacer.',
            ]);
        }

        if ($reception->isDispatched()) {
            throw ValidationException::withMessages([
                'outcome' => 'La recepción ya fue despachada: no se puede deshacer el dictamen.',
            ]);
        }

        if ($reception->repair_requested_at !== null || $reception->unrepairable_at !== null) {
            throw ValidationException::withMessages([
                'outcome' => 'El dictamen ya siguió su curso (consulta al cliente o sin solución): no se puede deshacer.',
            ]);
        }

        $previo = $reception->outcome;

        DB::transaction(function () use ($reception) {
            $reception->forceFill([
                // Vuelve a "en proceso": el inicio queda, el dictamen se borra.
                'released_at' => null,
                'released_by' => null,
                'outcome' => null,
                'outcome_notes' => null,
            ])->save();
        });

        ActivityLogger::log(
            action: 'reception.resolution_reverted',
            subject: $reception,
            description: 'Se deshizo el dictamen'.($reason !== null && $reason !== '' ? ': '.$reason : ''),
            new: ['reverted_from' => $previo, 'reason' => $reason],
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

        if ($reception->isUnrepairable()) {
            throw ValidationException::withMessages([
                'repair_requested_at' => 'Esta pieza ya se marcó sin solución.',
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

        $this->notifyOrganization($reception, new RepairAuthorizationRequested($reception));

        return $reception;
    }

    /**
     * Avisa a toda la organización dueña del equipo.
     *
     * El negocio pidió que llegue a todos sus correos, no sólo a quien puede
     * contestar: el de consulta audita el avance, y el correo de contacto de
     * la ficha suele ser el de compras, que es quien aprueba el gasto. Ese
     * correo no es un usuario, así que va por ruta aparte y sólo si no
     * coincide con uno que ya lo recibe.
     *
     * Va fuera de la transacción a propósito: si el correo falla, el hito
     * igual quedó registrado. Perder el aviso es molesto; perder el hito
     * sería peor.
     */
    private function notifyOrganization(Reception $reception, Notification $notification): void
    {
        $users = User::withoutGlobalScope('organization')
            ->where('organization_id', $reception->organization_id)
            ->where('is_active', true)
            ->get();

        if ($users->isNotEmpty()) {
            Notifier::send($users, $notification);
        }

        // Los correos de contacto de la ficha que no sean ya de un usuario.
        // Suele estar el de compras, que aprueba el gasto de la reparación.
        $contacts = Organization::splitEmails(
            Organization::query()->whereKey($reception->organization_id)->value('contact_email')
        );

        foreach ($contacts as $contact) {
            if (! $users->contains(fn (User $u) => strcasecmp($u->email, $contact) === 0)) {
                Notifier::route('mail', $contact)->notify($notification);
            }
        }
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
             * Autorizar no abre un estado "reparación" aparte: el negocio pidió
             * que el equipo vuelva a Pendiente y se re-trabaje como cualquier
             * otro. Se reinician los hitos del trabajo —no la consulta, que se
             * conserva para no volver a preguntar— y la etapa se deriva de nuevo
             * a 'pending'. Si se lo re-rechaza, ya sólo queda "sin solución".
             */
            if ($decision === Reception::REPAIR_APPROVED) {
                $reception->forceFill([
                    'started_at' => null, 'started_by' => null,
                    'released_at' => null, 'released_by' => null,
                    'outcome' => null, 'outcome_notes' => null,
                ])->save();
            }

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
     * El taller determina que la pieza no tiene solución.
     *
     * Es la salida que el negocio pidió para Rechazadas: no hace falta
     * consultarle al cliente si no hay nada que ofrecerle. Sólo vale desde
     * ahí; lo que está esperando al cliente sigue su propio curso.
     *
     * Cambia la aptitud igual que cuando el cliente no autoriza: el equipo
     * vuelve sin arreglar, así que no puede seguir figurando certificado. Se
     * le avisa a la organización porque lo pierde sin haberlo decidido.
     */
    public function markUnrepairable(Reception $reception, ?string $notes): Reception
    {
        if ($reception->stage() !== 'rejected') {
            throw ValidationException::withMessages([
                'unrepairable_at' => 'Sólo se marca sin solución una pieza rechazada que no esté esperando al cliente.',
            ]);
        }

        if (blank($notes)) {
            throw ValidationException::withMessages([
                'unrepairable_notes' => 'Indique por qué no tiene solución.',
            ]);
        }

        DB::transaction(function () use ($reception, $notes) {
            $reception->forceFill([
                'unrepairable_at' => now(),
                'unrepairable_by' => Auth::id(),
                'unrepairable_notes' => $notes,
            ])->save();

            if ($reception->asset !== null) {
                ($this->recalculate)($reception->asset);
            }
        });

        ActivityLogger::log(
            action: 'reception.unrepairable',
            subject: $reception,
            description: 'Se marcó sin solución',
            new: ['notes' => $notes],
        );

        $this->notifyOrganization($reception, new ReceptionDeclaredUnrepairable($reception));

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
                    ! $reception->repairWasRequested() => 'La pieza fue rechazada: consulte al cliente o márquela sin solución.',
                    $reception->repairWasApproved() => 'El cliente autorizó la reparación: debe volver a trabajarse y aprobarse antes de despachar.',
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
