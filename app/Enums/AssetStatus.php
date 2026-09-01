<?php

namespace App\Enums;

/**
 * Estado de aptitud de una pieza.
 *
 * El sistema auditado usa los códigos O/W/D en el querystring (`?estado=O`).
 * Se conservan como `legacyCode()` para que la migración de datos y los
 * enlaces antiguos sigan resolviendo.
 */
enum AssetStatus: string
{
    case Fit = 'fit';
    case Expiring = 'expiring';
    case Unfit = 'unfit';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Fit => 'Apto',
            self::Expiring => 'Próximo a vencer',
            self::Unfit => 'No apto',
            self::Unknown => 'Sin inspección',
        };
    }

    public function legacyCode(): ?string
    {
        return match ($this) {
            self::Fit => 'O',
            self::Expiring => 'W',
            self::Unfit => 'D',
            self::Unknown => null,
        };
    }

    public static function fromLegacyCode(?string $code): self
    {
        return match ($code) {
            'O' => self::Fit,
            'W' => self::Expiring,
            'D' => self::Unfit,
            default => self::Unknown,
        };
    }

    /** Tono semántico usado por los badges del frontend. */
    public function tone(): string
    {
        return match ($this) {
            self::Fit => 'success',
            self::Expiring => 'warning',
            self::Unfit => 'danger',
            self::Unknown => 'neutral',
        };
    }

    /**
     * Severidad, para poder comparar dos estados y quedarse con el peor.
     *
     * La usa el equipo compuesto: si un solo componente queda No apto, el
     * conjunto entero queda bloqueado, porque no se puede desplegar a medias.
     *
     * "Sin inspección" pesa más que "Apto" a propósito: un componente que
     * nunca se inspeccionó no permite afirmar que el conjunto esté apto.
     */
    public function severity(): int
    {
        return match ($this) {
            self::Unfit => 4,
            self::Unknown => 3,
            self::Expiring => 2,
            self::Fit => 1,
        };
    }

    /** El peor de dos estados. */
    public function worstWith(self $other): self
    {
        return $other->severity() > $this->severity() ? $other : $this;
    }

    public static function options(): array
    {
        return array_map(
            fn (self $c) => ['value' => $c->value, 'label' => $c->label(), 'tone' => $c->tone()],
            self::cases()
        );
    }
}
