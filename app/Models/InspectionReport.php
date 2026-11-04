<?php

namespace App\Models;

use App\Enums\ReportStatus;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;

/**
 * FMR — Field Maintenance Report.
 *
 * Documento que agrupa la inspección de N piezas en una fecha. Cada pieza
 * lleva su propio resultado y vencimiento en `inspection_report_asset`.
 */
class InspectionReport extends Model
{
    use BelongsToOrganization, HasFactory, HasPublicId, SoftDeletes;

    protected $fillable = [
        'organization_id', 'number', 'asset_set_id', 'equipment_label',
        'inspected_at', 'status', 'service_id', 'notes',
    ];

    /**
     * Un borrador es una declaración en curso del taller: el cliente no lo ve
     * hasta que se emite (pedido de Daniel, 04/11/2026).
     *
     * Va como scope global y no como filtro en cada pantalla porque por
     * cualquier camino que llegue —listado, ficha, panel, binding por ruta,
     * una relación— el borrador tiene que seguir oculto. El personal interno
     * no queda filtrado.
     */
    protected static function booted(): void
    {
        static::addGlobalScope('hide_drafts_from_clients', function (Builder $builder) {
            if (Auth::user()?->isClientUser()) {
                $builder->where($builder->getModel()->qualifyColumn('status'), '!=', ReportStatus::Draft->value);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'status' => ReportStatus::class,
            'inspected_at' => 'date',
            'finalized_at' => 'datetime',
        ];
    }

    public function assetSet(): BelongsTo
    {
        return $this->belongsTo(AssetSet::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function finalizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalized_by');
    }

    public function assets(): BelongsToMany
    {
        return $this->belongsToMany(Asset::class, 'inspection_report_asset')
            ->using(InspectionReportAsset::class)
            ->withPivot(['id', 'result', 'service_id', 'expires_at', 'seal', 'findings'])
            ->withTimestamps();
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InspectionReportAsset::class);
    }

    /** Documentos del informe completo (certificado, informe firmado). */
    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable')->latest();
    }

    public function receptions(): HasMany
    {
        return $this->hasMany(Reception::class);
    }

    public function comments(): MorphMany
    {
        return $this->morphMany(Comment::class, 'commentable');
    }

    /** Etiqueta del equipo: el Set asociado o el texto libre. */
    public function equipmentName(): ?string
    {
        return $this->assetSet?->name ?? $this->equipment_label;
    }

    public function scopeFinal(Builder $query): Builder
    {
        return $query->where('status', ReportStatus::Final->value);
    }
}
