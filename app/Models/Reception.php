<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Recepción de una pieza en el taller.
 *
 * El recorrido completo, tal como lo definió el negocio (revisado el 27/09/2026):
 *
 *   Pendiente → En proceso → Aprobada ───────────────────────────────→ Despachada
 *                   ↑      ↘ Rechazada ─ consulta ─┬─ SÍ → vuelve a Pendiente ┘ (se re-trabaja)
 *                   │                (lo decide    └─ NO → Despachada sin reparar
 *                   │                 el cliente)
 *                   └─ Sin solución (lo decide el taller) → Despachada sin reparar
 *
 * No es un recorrido lineal: se bifurca, y la bifurcación de la reparación no
 * la decide el taller sino el cliente, porque se le cobra. Cuando el cliente
 * autoriza, no hay un estado "reparación" aparte: el equipo vuelve a Pendiente
 * y se re-trabaja como cualquier otro. Se consulta una sola vez: si se lo
 * re-rechaza, la única salida que queda es "sin solución".
 *
 * Si el equipo sale sin reparar —porque el cliente no autorizó o porque no
 * tenía arreglo— igual vuelve al cliente, pero sin certificar: el activo queda
 * no apto, que es lo que corresponde y lo que después dispara los avisos de
 * recertificación.
 *
 * Ninguna etapa es una columna: todas se derivan de los hitos registrados,
 * de modo que la etiqueta no pueda contradecir lo que realmente pasó.
 */
class Reception extends Model
{
    use BelongsToOrganization, HasFactory, HasPublicId, SoftDeletes;

    protected $fillable = [
        'organization_id', 'asset_id', 'inspection_report_id', 'shipment_id', 'received_at',
        'promised_at', 'is_urgent', 'notes', 'received_by', 'dispatch_note',
    ];

    protected function casts(): array
    {
        return [
            'received_at' => 'date',
            'promised_at' => 'date',
            'is_urgent' => 'boolean',
            'started_at' => 'datetime',
            'released_at' => 'datetime',
            'repair_requested_at' => 'datetime',
            'repair_decided_at' => 'datetime',
            'repair_started_at' => 'datetime',
            'repaired_at' => 'datetime',
            'repair_failed_at' => 'datetime',
            'unrepairable_at' => 'datetime',
            'dispatched_at' => 'datetime',
        ];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function inspectionReport(): BelongsTo
    {
        return $this->belongsTo(InspectionReport::class);
    }

    /** Envío pre-cargado por el cliente que originó esta recepción, si lo hubo. */
    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    /** Línea del envío que la originó: dice qué pidió el cliente para este equipo. */
    public function shipmentItem(): HasOne
    {
        return $this->hasOne(ShipmentItem::class);
    }

    public function releaser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by');
    }

    public function dispatcher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dispatched_by');
    }

    /** Quién marcó el inicio del trabajo. */
    public function startedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by');
    }

    /** Quién la mandó a reparar y quién dio la reparación por terminada. */
    public function repairStartedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'repair_started_by');
    }

    public function repairedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'repaired_by');
    }

    public function repairFailedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'repair_failed_by');
    }

    public function unrepairableBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'unrepairable_by');
    }

    /** Quién le pidió al cliente que autorice la reparación. */
    public function repairRequestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'repair_requested_by');
    }

    /**
     * Quién dejó registrada la respuesta del cliente.
     *
     * Puede ser el propio cliente desde su panel, o alguien de Odessa cargando
     * lo que recibió por teléfono o correo. El canal lo distingue.
     */
    public function repairDecidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'repair_decided_by');
    }

    /**
     * Adjuntos de la recepción.
     *
     * Se usan sobre todo para respaldar la autorización de reparación cuando
     * llegó por fuera del sistema: la captura del correo o del mensaje es lo
     * que después justifica el cobro.
     */
    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function comments(): MorphMany
    {
        return $this->morphMany(Comment::class, 'commentable');
    }

    public function isReleased(): bool
    {
        return $this->released_at !== null;
    }

    public function isDispatched(): bool
    {
        return $this->dispatched_at !== null;
    }

    /** Dictamen del trabajo del taller. */
    public const OUTCOME_APPROVED = 'approved';

    public const OUTCOME_REJECTED = 'rejected';

    /** Respuesta del cliente a la consulta de reparación. */
    public const REPAIR_APPROVED = 'approved';

    public const REPAIR_DECLINED = 'declined';

    /**
     * Por dónde llegó la respuesta del cliente.
     *
     * `panel` es la única que el sistema puede dar por cierta por sí misma: la
     * cargó el propio cliente estando autenticado. Las demás las transcribe
     * alguien de Odessa, y por eso conviene que vengan con evidencia adjunta.
     */
    public const DECISION_CHANNELS = [
        'panel' => 'Desde el panel del cliente',
        'email' => 'Correo electrónico',
        'phone' => 'Teléfono',
        'whatsapp' => 'WhatsApp',
        'in_person' => 'En persona',
    ];

    /**
     * Etapas del equipo dentro del taller.
     *
     * Terminar de trabajar no es un solo final: es un dictamen. Y un equipo
     * rechazado tampoco tiene un solo destino, porque la reparación la paga el
     * cliente y por lo tanto la decide el cliente, salvo que el taller
     * determine que no tiene solución.
     *
     * La reparación en curso se muestra como "En proceso": para el taller es
     * un trabajo más, que se aprueba o se rechaza igual que el original.
     */
    public const STAGE_LABELS = [
        'pending' => 'Pendiente',
        'in_progress' => 'En proceso',
        'approved' => 'Aprobada',
        'rejected' => 'Rechazada',
        'awaiting_client' => 'Esperando al cliente',
        'repair_declined' => 'Sin reparar · a devolver',
        'unrepairable' => 'Sin solución · a devolver',
        'dispatched' => 'Despachada',
        'returned_unrepaired' => 'Despachada sin reparar',
    ];

    public const STAGE_DESCRIPTIONS = [
        'pending' => 'Recibida en el taller. Todavía no se empezó a trabajar.',
        'in_progress' => 'El taller está trabajando el equipo.',
        'approved' => 'El trabajo se aprobó. Lista para despachar.',
        'rejected' => 'El trabajo se rechazó. Falta consultarle al cliente si autoriza la reparación, o marcarla sin solución.',
        'awaiting_client' => 'Se le pidió al cliente que autorice la reparación. El equipo espera su respuesta.',
        'repair_declined' => 'El cliente no autorizó la reparación. Se le devuelve como está, sin certificar.',
        'unrepairable' => 'El taller determinó que no tiene solución. Se devuelve sin certificar.',
        'dispatched' => 'Se envió al cliente.',
        'returned_unrepaired' => 'Se le devolvió al cliente sin reparar. El equipo quedó no apto.',
    ];

    /**
     * Etapa actual, derivada de los hechos registrados.
     *
     * Se lee de atrás hacia adelante: gana el hito más avanzado que ocurrió,
     * de modo que la etiqueta nunca puede contradecir la historia.
     */
    public function stage(): string
    {
        return match (true) {
            // Ya despachada: importa distinguir si salió reparada o como
            // estaba, porque son dos cosas muy distintas para el cliente y
            // para cualquiera que audite después.
            $this->dispatched_at !== null && $this->leavesUnrepaired() => 'returned_unrepaired',
            $this->dispatched_at !== null => 'dispatched',

            $this->unrepairable_at !== null => 'unrepairable',

            // El cliente no autorizó: el equipo se devuelve como está.
            $this->repairWasDeclined() => 'repair_declined',

            // Consulta hecha y todavía sin respuesta. Si el cliente autorizó,
            // los hitos de trabajo se reiniciaron y la pieza volvió a
            // Pendiente para re-trabajarse, así que no cae acá.
            $this->repair_requested_at !== null && $this->repair_decision === null => 'awaiting_client',

            $this->released_at !== null && $this->outcome === self::OUTCOME_REJECTED => 'rejected',
            $this->released_at !== null => 'approved',
            $this->started_at !== null => 'in_progress',
            default => 'pending',
        };
    }

    public function stageLabel(): string
    {
        return self::STAGE_LABELS[$this->stage()];
    }

    public function stageTone(): string
    {
        return match ($this->stage()) {
            'dispatched' => 'success',
            'approved' => 'info',
            // Devuelta sin reparar no es un éxito ni un error: es una salida
            // legítima que conviene que se vea distinta de una despachada
            // certificada, porque el equipo se fue no apto.
            'returned_unrepaired' => 'danger',
            'rejected', 'repair_declined', 'unrepairable' => 'danger',
            'awaiting_client', 'in_progress' => 'warning',
            default => 'neutral',
        };
    }

    public function hasStarted(): bool
    {
        return $this->started_at !== null;
    }

    /** ¿Ya se emitió el dictamen del trabajo? */
    public function isResolved(): bool
    {
        return $this->released_at !== null;
    }

    public function wasRejected(): bool
    {
        return $this->outcome === self::OUTCOME_REJECTED;
    }

    /** ¿Se le pidió al cliente que decida sobre la reparación? */
    public function repairWasRequested(): bool
    {
        return $this->repair_requested_at !== null;
    }

    public function repairWasApproved(): bool
    {
        return $this->repair_decision === self::REPAIR_APPROVED;
    }

    public function repairWasDeclined(): bool
    {
        return $this->repair_decision === self::REPAIR_DECLINED;
    }

    public function isUnrepairable(): bool
    {
        return $this->unrepairable_at !== null;
    }

    /** Sale sin reparar: el cliente no autorizó, o el taller determinó que no tenía arreglo. */
    public function leavesUnrepaired(): bool
    {
        return $this->repairWasDeclined() || $this->isUnrepairable();
    }

    /**
     * Enlace directo a esta recepción, abierto en la pestaña que la incluye
     * siempre. Lo usan los avisos por correo y el hilo del equipo.
     */
    public function url(): string
    {
        return '/taller/recepciones?stage=all&reception='.$this->public_id;
    }

    /** ¿Está trabada esperando que el cliente conteste? */
    public function isAwaitingClient(): bool
    {
        return $this->stage() === 'awaiting_client';
    }

    public function decisionChannelLabel(): ?string
    {
        return self::DECISION_CHANNELS[$this->repair_decision_channel] ?? null;
    }

    /**
     * ¿Puede despacharse?
     *
     * Tres salidas legítimas, y ninguna más:
     *  - se aprobó el trabajo (sea la primera vez o tras re-trabajar una
     *    reparación autorizada);
     *  - se rechazó y el cliente decidió no repararla;
     *  - se rechazó y el taller determinó que no tiene solución.
     * En los dos últimos casos se devuelve como está: sale sin certificar, y
     * eso queda registrado.
     *
     * Lo que se sigue impidiendo es despachar algo rechazado sobre lo que
     * nadie decidió nada.
     */
    public function isDispatchable(): bool
    {
        if ($this->dispatched_at !== null) {
            return false;
        }

        return $this->leavesUnrepaired()
            || ($this->isResolved() && ! $this->wasRejected());
    }

    /** ¿Pasó la fecha prometida sin haberse liberado? */
    public function isOverdue(): bool
    {
        return $this->promised_at !== null
            && $this->released_at === null
            && $this->promised_at->isPast();
    }

    public function scopeInWorkshop(Builder $query): Builder
    {
        return $query->whereNull('dispatched_at');
    }

    public function scopeDispatched(Builder $query): Builder
    {
        return $query->whereNotNull('dispatched_at');
    }

    /**
     * Trabajo abierto: todo lo que todavía no se liberó ni despachó, esté
     * empezado o no. Es el volumen que el taller tiene sobre la mesa.
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('released_at')->whereNull('dispatched_at');
    }

    /** Recibida y sin tocar. Es la cola real de entrada. */
    public function scopePending(Builder $query): Builder
    {
        return $query->open()->whereNull('started_at');
    }

    /** Sobre la mesa del taller: empezado y sin dictamen. Se aprueba o se rechaza. */
    public function scopeInProgress(Builder $query): Builder
    {
        return $query->open()->whereNotNull('started_at');
    }

    /**
     * Rechazadas que esperan una decisión del taller: consultar al cliente o
     * marcarlas sin solución. Lo que ya está esperando al cliente sale de acá.
     * Una que el cliente autorizó y se re-trabajó, si se la vuelve a rechazar,
     * cae de nuevo acá: su única salida ya es "sin solución".
     */
    public function scopeRejected(Builder $query): Builder
    {
        return $query->where('outcome', self::OUTCOME_REJECTED)
            ->whereNull('unrepairable_at')
            ->whereNull('dispatched_at')
            // No está esperando al cliente (consultada y sin respuesta).
            ->where(fn (Builder $q) => $q
                ->whereNull('repair_requested_at')
                ->orWhereNotNull('repair_decision'))
            // Y el cliente no la rechazó (esas ya están para despachar).
            ->where(fn (Builder $q) => $q
                ->whereNull('repair_decision')
                ->orWhere('repair_decision', '!=', self::REPAIR_DECLINED));
    }

    /**
     * Esperando que el cliente conteste si autoriza la reparación.
     *
     * Es la cola que traba el taller sin que sea culpa del taller: conviene
     * poder listarla aparte para reclamarle al cliente.
     */
    public function scopeAwaitingClient(Builder $query): Builder
    {
        return $query->whereNotNull('repair_requested_at')
            ->whereNull('repair_decision')
            ->whereNull('dispatched_at');
    }

    /**
     * Listas para salir, en cualquiera de sus tres formas: aprobadas,
     * rechazadas cuyo cliente decidió no repararlas, o sin solución —esas dos
     * vuelven como están, sin certificar.
     *
     * Tiene que coincidir con `isDispatchable()`; si divergen, la lista
     * mostraría filas cuyo botón de despachar da error.
     */
    public function scopeReadyToDispatch(Builder $query): Builder
    {
        return $query->whereNull('dispatched_at')
            ->where(fn (Builder $q) => $q
                ->where('repair_decision', self::REPAIR_DECLINED)
                ->orWhereNotNull('unrepairable_at')
                ->orWhere(fn (Builder $i) => $i
                    ->whereNotNull('released_at')
                    // En SQL, NULL != 'rejected' no es verdadero: sin esto una
                    // liberada sin dictamen quedaría fuera, y en PHP no.
                    ->where(fn (Builder $o) => $o
                        ->whereNull('outcome')
                        ->orWhere('outcome', '!=', self::OUTCOME_REJECTED))));
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query->whereNull('released_at')
            ->whereNotNull('promised_at')
            ->whereDate('promised_at', '<', today());
    }
}
