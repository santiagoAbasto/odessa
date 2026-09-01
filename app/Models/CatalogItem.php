<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Modelo de catálogo: el "qué es" de una pieza, compartido entre clientes. */
class CatalogItem extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'kind', 'description', 'part_number', 'manufacturer_id',
        'turnaround_days', 'validity_days', 'determines_fitness',
    ];

    protected function casts(): array
    {
        return [
            'turnaround_days' => 'integer',
            'validity_days' => 'integer',
            'determines_fitness' => 'boolean',
        ];
    }

    /**
     * Días que tarda el taller con este tipo de equipo. Sin valor propio, el
     * de la operación: no todo tipo justifica una medición propia.
     */
    public function turnaroundDays(): int
    {
        return $this->turnaround_days ?? (int) config('odessa.recertification.turnaround_days');
    }

    public function manufacturer(): BelongsTo
    {
        return $this->belongsTo(Manufacturer::class);
    }

    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class);
    }

    public function scopeOfKind(Builder $query, string $kind): Builder
    {
        return $query->where('kind', $kind);
    }
}
