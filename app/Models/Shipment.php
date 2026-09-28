<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Envío pre-cargado por el cliente.
 *
 * El cliente declara qué equipos manda; el taller lo autoriza y, en ese
 * momento, se crean las recepciones. Hasta la autorización, nada entra al
 * inventario ni al pipeline: un envío es una declaración, no un ingreso.
 */
class Shipment extends Model
{
    use BelongsToOrganization, HasFactory, HasPublicId, SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_AUTHORIZED = 'authorized';

    public const STATUS_REJECTED = 'rejected';

    /**
     * El negocio lo nombra por lo que pasa físicamente: el cliente lo manda,
     * queda pendiente de recepción, y cuando llega el taller le da recepción
     * confirmando lo declarado. Las claves internas conservan el nombre
     * original para no migrar datos.
     */
    public const STATUS_LABELS = [
        self::STATUS_DRAFT => 'Borrador',
        self::STATUS_SUBMITTED => 'Pendiente de recepción',
        self::STATUS_AUTHORIZED => 'Recibido',
        self::STATUS_REJECTED => 'Rechazado',
    ];

    protected $fillable = [
        'organization_id', 'reference', 'client_reference', 'expected_at',
        'contact_name', 'contact_phone', 'carrier', 'origin', 'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'expected_at' => 'date',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(ShipmentItem::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function receptions(): HasMany
    {
        return $this->hasMany(Reception::class);
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    public function statusTone(): string
    {
        return match ($this->status) {
            self::STATUS_DRAFT => 'neutral',
            self::STATUS_SUBMITTED => 'info',
            self::STATUS_AUTHORIZED => 'success',
            self::STATUS_REJECTED => 'danger',
            default => 'neutral',
        };
    }

    /** Sólo un borrador admite cambios del cliente. */
    public function isEditable(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_SUBMITTED;
    }
}
