<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Certificación que el equipo ya traía de otra empresa, cargada a mano.
 *
 * No es un informe de Odessa y no pasa por el circuito del taller: es un dato
 * de la ficha técnica. Vale para el estado y los vencimientos del equipo hasta
 * que Odessa certifique esa misma operación. Ver RecalculateAssetStatus.
 */
class AssetExternalCertification extends Model
{
    protected $fillable = [
        'asset_id', 'service_id', 'certified_by', 'certificate_number',
        'certified_at', 'expires_at', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return ['certified_at' => 'date', 'expires_at' => 'date'];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }
}
