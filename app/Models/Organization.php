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

    /**
     * Correos de contacto de la empresa, uno por línea en `contact_email`.
     *
     * Son las casillas que reciben los avisos aunque no sean usuarios del
     * sistema —típicamente compras, que aprueba el gasto de una reparación—.
     * El negocio pidió poder cargar varias para las comunicaciones masivas.
     *
     * @return list<string>
     */
    public function contactEmails(): array
    {
        return self::splitEmails($this->contact_email);
    }

    /**
     * Separa una lista de correos escrita a mano: admite saltos de línea,
     * comas y punto y coma, descarta vacíos y repetidos sin distinguir
     * mayúsculas.
     *
     * @return list<string>
     */
    public static function splitEmails(?string $raw): array
    {
        $emails = preg_split('/[\r\n,;]+/', (string) $raw) ?: [];

        return collect($emails)
            ->map(fn (string $e) => trim($e))
            ->filter()
            ->unique(fn (string $e) => mb_strtolower($e))
            ->values()
            ->all();
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
