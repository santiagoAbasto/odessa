<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Set — agrupación nombrada de piezas que se despliegan juntas.
 *
 * En el sistema auditado los sets se nombran como equipos completos
 * (p. ej. "Equipo #N (FABRICANTE medida-presión)"). Agrupar no altera la
 * identidad ni la trazabilidad individual de cada pieza.
 */
class AssetSet extends Model
{
    use BelongsToOrganization, HasFactory, HasPublicId, SoftDeletes;

    protected $fillable = ['organization_id', 'name', 'notes'];

    public function assets(): BelongsToMany
    {
        return $this->belongsToMany(Asset::class, 'asset_set_asset')
            ->withPivot('position')
            ->withTimestamps()
            ->orderBy('asset_set_asset.position');
    }

    public function inspectionReports(): HasMany
    {
        return $this->hasMany(InspectionReport::class);
    }
}
