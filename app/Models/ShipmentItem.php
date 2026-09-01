<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
        'is_urgent', 'notes',
    ];

    protected function casts(): array
    {
        return ['is_urgent' => 'boolean'];
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
