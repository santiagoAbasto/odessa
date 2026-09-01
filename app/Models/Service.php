<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Servicio de inspección (ej. certificación, operacional, mantenimiento mayor).
 *
 * `determines_fitness` decide si el servicio emite dictamen Apto/No Apto y
 * renueva la vigencia, o si sólo queda registrado en la bitácora.
 */
class Service extends Model
{
    use HasFactory;

    protected $fillable = ['code', 'name', 'determines_fitness', 'validity_days', 'turnaround_days', 'is_active'];

    protected function casts(): array
    {
        return [
            'determines_fitness' => 'boolean',
            'is_active' => 'boolean',
            'validity_days' => 'integer',
            'turnaround_days' => 'integer',
        ];
    }

    /**
     * Días que lleva esta operación. Sin valor propio, el de la operación
     * general: no toda operación justifica una medición aparte.
     */
    public function turnaroundDays(): int
    {
        return $this->turnaround_days ?? (int) config('odessa.recertification.turnaround_days');
    }

    public function label(): string
    {
        return "{$this->name} ({$this->code})";
    }
}
