<?php

namespace App\Enums;

/**
 * Resultado de un activo dentro de un FMR.
 *
 * Observado en la biblioteca de una parte: los valores "Apto", "No Apto",
 * "No Aplica" y "Sin Procesar" conviven en el histórico de la misma pieza.
 *
 * REGLA CRÍTICA: sólo Apto y NoApto determinan el estado vigente de la pieza.
 * El sistema legacy lo declara textualmente: "La Inspección Nro. N es la última
 * que determinó el estado (Apto/No Apto) de esta parte."
 */
enum InspectionResult: string
{
    case Apto = 'apto';
    case NoApto = 'no_apto';
    case NoAplica = 'no_aplica';
    case SinProcesar = 'sin_procesar';

    public function label(): string
    {
        return match ($this) {
            self::Apto => 'Apto',
            self::NoApto => 'No apto',
            self::NoAplica => 'No aplica',
            self::SinProcesar => 'Sin procesar',
        };
    }

    /** ¿Este resultado fija el estado de aptitud de la pieza? */
    public function determinesFitness(): bool
    {
        return $this === self::Apto || $this === self::NoApto;
    }

    public function tone(): string
    {
        return match ($this) {
            self::Apto => 'success',
            self::NoApto => 'danger',
            self::NoAplica => 'neutral',
            self::SinProcesar => 'info',
        };
    }

    public static function options(): array
    {
        return array_map(
            fn (self $c) => ['value' => $c->value, 'label' => $c->label(), 'tone' => $c->tone()],
            self::cases()
        );
    }
}
