<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Organization extends Model
{
    use HasFactory, HasPublicId, SoftDeletes;

    protected $fillable = [
        'name', 'short_code', 'tax_id', 'contact_email', 'contact_phone', 'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class);
    }

    public function inspectionReports(): HasMany
    {
        return $this->hasMany(InspectionReport::class);
    }

    public function assetSets(): HasMany
    {
        return $this->hasMany(AssetSet::class);
    }

    public function receptions(): HasMany
    {
        return $this->hasMany(Reception::class);
    }

    /**
     * Operaciones que Odessa le habilitó a esta empresa, con lo que la
     * empresa ajusta de cada una: nombre, descripción y vigencia. Un ajuste
     * vacío deja valer el del catálogo.
     */
    public function operations(): BelongsToMany
    {
        return $this->belongsToMany(Service::class)
            ->withPivot(['name', 'description', 'validity_days'])
            ->withTimestamps();
    }

    /**
     * Días de vigencia que esta empresa tiene ajustados, por operación.
     *
     * @return array<int, int>
     */
    public function validityOverrides(): array
    {
        return $this->operations()
            ->wherePivotNotNull('validity_days')
            ->pluck('organization_service.validity_days', 'services.id')
            ->map(fn ($days) => (int) $days)
            ->all();
    }
}
