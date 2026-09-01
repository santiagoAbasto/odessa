<?php

namespace App\Enums;

/**
 * Ciclo de vida de un FMR.
 *
 * La nomenclatura de archivos del sistema auditado distingue "Preliminar" y
 * "Final" (ej. "FMR781 ... - Preliminar.pdf" / "- Final.pdf"), lo que confirma
 * que el informe se emite en dos etapas antes de quedar firme.
 */
enum ReportStatus: string
{
    case Draft = 'draft';
    case Preliminary = 'preliminary';
    case Final = 'final';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Borrador',
            self::Preliminary => 'Preliminar',
            self::Final => 'Final',
            self::Cancelled => 'Anulado',
        };
    }

    /** Sólo un FMR final propaga vencimientos y estado a las piezas. */
    public function isBinding(): bool
    {
        return $this === self::Final;
    }

    /** Un FMR final o anulado ya no admite edición de resultados. */
    public function isEditable(): bool
    {
        return $this === self::Draft || $this === self::Preliminary;
    }

    public function tone(): string
    {
        return match ($this) {
            self::Draft => 'neutral',
            self::Preliminary => 'info',
            self::Final => 'success',
            self::Cancelled => 'danger',
        };
    }

    /** Transiciones permitidas; cualquier otra se rechaza en la Action. */
    public function canTransitionTo(self $target): bool
    {
        return in_array($target, match ($this) {
            self::Draft => [self::Preliminary, self::Final, self::Cancelled],
            self::Preliminary => [self::Final, self::Draft, self::Cancelled],
            self::Final => [self::Cancelled],
            self::Cancelled => [],
        }, true);
    }

    public static function options(): array
    {
        return array_map(
            fn (self $c) => ['value' => $c->value, 'label' => $c->label(), 'tone' => $c->tone()],
            self::cases()
        );
    }
}
