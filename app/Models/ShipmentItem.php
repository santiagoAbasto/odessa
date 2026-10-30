<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Línea de un envío: una pieza declarada por el cliente.
 *
 * Si la pieza ya está registrada queda enlazada por `asset_id`. Si es nueva,
 * el cliente la describe y el taller la da de alta al autorizar — el alta de
 * inventario sigue siendo responsabilidad del taller.
 */
class ShipmentItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'shipment_id', 'asset_id', 'declared_description', 'declared_serial',
        'is_urgent', 'notes', 'special_operation', 'not_arrived_at', 'not_arrived_reason',
    ];

    protected function casts(): array
    {
        return ['is_urgent' => 'boolean', 'not_arrived_at' => 'datetime'];
    }

    /** El taller marcó que este equipo no llegó: no entró al taller. */
    public function didNotArrive(): bool
    {
        return $this->not_arrived_at !== null;
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function reception(): BelongsTo
    {
        return $this->belongsTo(Reception::class);
    }

    /** Lo que el cliente pide para este equipo, de sus operaciones habilitadas. */
    public function operations(): BelongsToMany
    {
        return $this->belongsToMany(Service::class, 'shipment_item_operations');
    }

    /**
     * Nombres de lo pedido tal como los conoce esta empresa.
     *
     * @param  array<int, string>  $clientNames  nombre por id de operación, de la empresa del envío
     * @return list<string>
     */
    public function requestedNames(array $clientNames, bool $withCode = false): array
    {
        return $this->operations
            ->map(fn (Service $s) => ($clientNames[$s->getKey()] ?? $s->name).($withCode ? " ({$s->code})" : ''))
            ->values()
            ->all();
    }

    /** Qué se muestra al taller: la pieza registrada o lo declarado. */
    public function displayName(): string
    {
        return $this->asset?->displayName()
            ?: ($this->declared_description ?: 'Pieza sin describir');
    }

    public function displaySerial(): ?string
    {
        return $this->asset?->serial_number ?: $this->declared_serial;
    }

    public function isRegistered(): bool
    {
        return $this->asset_id !== null;
    }
}
