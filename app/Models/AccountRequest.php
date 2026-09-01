<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Solicitud pública de cuenta.
 *
 * Réplica del formulario `/usuarios/registrar` del sistema auditado
 * (email, nombre, teléfono, empresa). El alta real la hace un administrador:
 * el solicitante nunca elige su propia contraseña ni su organización.
 */
class AccountRequest extends Model
{
    use HasFactory, HasPublicId;

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    protected $fillable = ['email', 'name', 'phone', 'company', 'message', 'ip_address'];

    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime'];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }
}
