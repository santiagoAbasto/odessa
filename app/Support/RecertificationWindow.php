<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Ventana de recertificación: hasta cuándo puede el cliente mandar un equipo
 * sin que pierda la certificación.
 *
 * La regla del negocio es que **el equipo nunca deja de estar certificado**.
 * Como el taller tarda en trabajarlo, no alcanza con avisar cuando la
 * certificación está por vencer: para entonces ya es tarde. Hay que avisar
 * con la anticipación suficiente para que el equipo entre, se trabaje y
 * salga antes del vencimiento.
 *
 *     Fecha límite de envío = vencimiento − tiempo de reparación − margen
 *
 * Ejemplo real dado por el negocio: una certificación que vence el 30 de
 * octubre, con un mes de reparación y quince días de margen, obliga al
 * cliente a despachar el equipo antes del 15 de septiembre.
 *
 * El margen no es capricho: cubre el transporte desde el yacimiento, la
 * autorización del envío y los imprevistos del taller. Sin él, cualquier
 * demora de un día hace perder la certificación.
 */
class RecertificationWindow
{
    /** Ya pasó la fecha de envío y todavía no llegó: no llega a tiempo. */
    public const STATE_MISSED = 'missed';

    /** Está dentro de la ventana de aviso: hay que mandarlo ya. */
    public const STATE_DUE = 'due';

    /** Todavía hay tiempo. */
    public const STATE_OK = 'ok';

    /** No hay vencimiento conocido: no se puede calcular nada. */
    public const STATE_UNKNOWN = 'unknown';

    public function __construct(
        public readonly ?Carbon $expiresAt,
        public readonly int $turnaroundDays,
        public readonly int $safetyMarginDays,
    ) {}

    /**
     * Días totales de anticipación con los que hay que mandar el equipo.
     */
    public function leadTimeDays(): int
    {
        return $this->turnaroundDays + $this->safetyMarginDays;
    }

    /**
     * Última fecha en la que el cliente puede despachar el equipo y aun así
     * recibirlo certificado. Es la fecha que se le comunica.
     */
    public function sendBy(): ?Carbon
    {
        return $this->expiresAt?->copy()->subDays($this->leadTimeDays())->startOfDay();
    }

    /**
     * Días que le quedan al cliente para despachar. Negativo si ya se pasó.
     */
    public function daysToSendBy(?Carbon $today = null): ?int
    {
        $sendBy = $this->sendBy();

        if ($sendBy === null) {
            return null;
        }

        return (int) ($today ?? today())->startOfDay()->diffInDays($sendBy, false);
    }

    /**
     * Estado de la ventana.
     *
     * `$noticeDays` es con cuánta antelación a la fecha límite se empieza a
     * avisar. Se avisa antes de la fecha límite, no el mismo día: el aviso
     * tiene que darle tiempo al cliente a organizar el transporte.
     */
    public function state(?int $noticeDays = null, ?Carbon $today = null): string
    {
        $days = $this->daysToSendBy($today);

        if ($days === null) {
            return self::STATE_UNKNOWN;
        }

        $noticeDays ??= (int) config('odessa.recertification.notice_days');

        return match (true) {
            $days < 0 => self::STATE_MISSED,
            $days <= $noticeDays => self::STATE_DUE,
            default => self::STATE_OK,
        };
    }

    public function stateLabel(?int $noticeDays = null, ?Carbon $today = null): string
    {
        return match ($this->state($noticeDays, $today)) {
            self::STATE_MISSED => 'Fuera de plazo para recertificar',
            self::STATE_DUE => 'Debe enviarse ahora',
            self::STATE_OK => 'En plazo',
            default => 'Sin vencimiento',
        };
    }

    public function stateTone(?int $noticeDays = null, ?Carbon $today = null): string
    {
        return match ($this->state($noticeDays, $today)) {
            self::STATE_MISSED => 'danger',
            self::STATE_DUE => 'warning',
            self::STATE_OK => 'success',
            default => 'neutral',
        };
    }

    /**
     * Frase que se le manda al cliente. Dice qué pasa y qué tiene que hacer,
     * sin obligarlo a calcular nada.
     */
    public function clientMessage(?Carbon $today = null): string
    {
        $sendBy = $this->sendBy();

        if ($sendBy === null) {
            return 'Este equipo no tiene fecha de vencimiento registrada.';
        }

        $vence = $this->expiresAt->format('d/m/Y');
        $limite = $sendBy->format('d/m/Y');
        $days = $this->daysToSendBy($today);

        if ($days < 0) {
            return sprintf(
                'La certificación vence el %s. La fecha límite de envío era el %s, '.
                'hace %d días. Si se despacha ahora, el equipo perderá la certificación '.
                'antes de que el taller termine: coordine con Odessa Oil.',
                $vence, $limite, abs($days),
            );
        }

        return sprintf(
            'La certificación vence el %s. Para que el equipo no pierda vigencia, '.
            'debe despacharlo antes del %s (quedan %d días). El taller necesita '.
            '%d días para trabajarlo, más %d de margen.',
            $vence, $limite, $days, $this->turnaroundDays, $this->safetyMarginDays,
        );
    }
}
