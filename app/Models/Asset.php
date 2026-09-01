<?php

namespace App\Models;

use App\Enums\AssetStatus;
use App\Enums\InspectionResult;
use App\Support\RecertificationWindow;
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

/**
 * Una pieza física individual perteneciente a un cliente.
 *
 * `kind` distingue "parte" (con ficha técnica: presiones, conexiones) de
 * "genérico" (marca/modelo/identificador). Ambos comparten el ciclo de
 * inspección, por eso viven en la misma tabla.
 */
class Asset extends Model
{
    use BelongsToOrganization, HasFactory, HasPublicId, SoftDeletes;

    public const KIND_PART = 'part';

    public const KIND_GENERIC = 'generic';

    protected $fillable = [
        'organization_id', 'parent_id', 'catalog_item_id', 'kind', 'serial_number', 'code',
        'client_reference', 'manufacturer_reference', 'description', 'workshop_description',
        'brand', 'model', 'identifier', 'manufacturer_id', 'test_pressure_id',
        'working_pressure_id', 'service_id', 'connection_top_id', 'connection_bottom_id',
        'connection_side_1_id', 'connection_side_2_id',
    ];

    /**
     * status / expires_at / current_inspection_report_id son derivados.
     * Se escriben únicamente desde RecalculateAssetStatus, nunca por asignación masiva.
     */
    protected function casts(): array
    {
        return [
            'status' => AssetStatus::class,
            'composite_status' => AssetStatus::class,
            'expires_at' => 'date',
            'composite_expires_at' => 'date',
            'send_by_at' => 'date',
        ];
    }

    /** Conjunto del que esta pieza es componente, si lo es. */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** Piezas que componen este equipo. */
    public function components(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function catalogItem(): BelongsTo
    {
        return $this->belongsTo(CatalogItem::class);
    }

    public function manufacturer(): BelongsTo
    {
        return $this->belongsTo(Manufacturer::class);
    }

    public function testPressure(): BelongsTo
    {
        return $this->belongsTo(PressureRating::class, 'test_pressure_id');
    }

    public function workingPressure(): BelongsTo
    {
        return $this->belongsTo(PressureRating::class, 'working_pressure_id');
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function connectionTop(): BelongsTo
    {
        return $this->belongsTo(Connection::class, 'connection_top_id');
    }

    public function connectionBottom(): BelongsTo
    {
        return $this->belongsTo(Connection::class, 'connection_bottom_id');
    }

    public function connectionSide1(): BelongsTo
    {
        return $this->belongsTo(Connection::class, 'connection_side_1_id');
    }

    public function connectionSide2(): BelongsTo
    {
        return $this->belongsTo(Connection::class, 'connection_side_2_id');
    }

    public function currentReport(): BelongsTo
    {
        return $this->belongsTo(InspectionReport::class, 'current_inspection_report_id');
    }

    /** Bitácora completa: todos los FMR donde apareció esta pieza. */
    public function inspectionReports(): BelongsToMany
    {
        return $this->belongsToMany(InspectionReport::class, 'inspection_report_asset')
            ->using(InspectionReportAsset::class)
            ->withPivot(['id', 'result', 'service_id', 'expires_at', 'seal', 'findings'])
            ->withTimestamps();
    }

    public function inspectionLines(): HasMany
    {
        return $this->hasMany(InspectionReportAsset::class);
    }

    public function receptions(): HasMany
    {
        return $this->hasMany(Reception::class);
    }

    public function sets(): BelongsToMany
    {
        return $this->belongsToMany(AssetSet::class, 'asset_set_asset')->withPivot('position')->withTimestamps();
    }

    public function comments(): MorphMany
    {
        return $this->morphMany(Comment::class, 'commentable');
    }

    /** Documentos propios de la pieza, independientes de cualquier FMR. */
    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable')->latest();
    }

    public function shipmentItems(): HasMany
    {
        return $this->hasMany(ShipmentItem::class);
    }

    /** Nombre legible: descripción propia o la del catálogo. */
    public function displayName(): string
    {
        return $this->description
            ?: $this->catalogItem?->description
            ?: $this->workshop_description
            ?: ($this->serial_number ?? 'Sin descripción');
    }

    public function scopeParts(Builder $query): Builder
    {
        return $query->where('kind', self::KIND_PART);
    }

    public function scopeGenerics(Builder $query): Builder
    {
        return $query->where('kind', self::KIND_GENERIC);
    }

    public function scopeStatus(Builder $query, AssetStatus|string $status): Builder
    {
        return $query->where('status', $status instanceof AssetStatus ? $status->value : $status);
    }

    /** Piezas que vencen dentro de N días y aún están aptas. */
    public function scopeExpiringWithin(Builder $query, int $days): Builder
    {
        return $query->whereNotNull('expires_at')
            ->whereDate('expires_at', '>=', today())
            ->whereDate('expires_at', '<=', today()->addDays($days));
    }

    /**
     * Última línea de FMR que fijó el dictamen (Apto/No Apto) de esta pieza.
     * Las líneas "No aplica"/"Sin procesar" se ignoran a propósito.
     */
    public function determiningLine(): ?InspectionReportAsset
    {
        return $this->inspectionLines()
            ->whereIn('result', [InspectionResult::Apto->value, InspectionResult::NoApto->value])
            ->whereHas('inspectionReport', fn (Builder $q) => $q->where('status', 'final'))
            ->join('inspection_reports', 'inspection_reports.id', '=', 'inspection_report_asset.inspection_report_id')
            ->orderByDesc('inspection_reports.inspected_at')
            ->orderByDesc('inspection_report_asset.id')
            ->select('inspection_report_asset.*')
            ->first();
    }

    /**
     * Ventana de recertificación de esta pieza.
     *
     * El tiempo de reparación sale del tipo de equipo cuando lo tiene propio;
     * una válvula y un preventor no tardan lo mismo.
     */
    public function recertificationWindow(): RecertificationWindow
    {
        return new RecertificationWindow(
            expiresAt: $this->expires_at,
            turnaroundDays: $this->catalogItem?->turnaroundDays()
                ?? (int) config('odessa.recertification.turnaround_days'),
            safetyMarginDays: (int) config('odessa.recertification.safety_margin_days'),
        );
    }

    /** Piezas cuya fecha límite de envío ya pasó y siguen sin entrar al taller. */
    public function scopeSendDeadlineMissed(Builder $query): Builder
    {
        return $query->whereNotNull('send_by_at')->whereDate('send_by_at', '<', today());
    }

    /** Piezas que hay que despachar dentro de los próximos `$days` días. */
    public function scopeSendDeadlineWithin(Builder $query, int $days): Builder
    {
        return $query->whereNotNull('send_by_at')
            ->whereDate('send_by_at', '>=', today())
            ->whereDate('send_by_at', '<=', today()->addDays($days));
    }

    /** ¿Este equipo está formado por otras piezas? */
    public function isComposite(): bool
    {
        return $this->components()->exists();
    }

    /**
     * Estado con el que hay que decidir si el equipo se puede desplegar.
     *
     * Para una pieza suelta es su propio estado. Para un conjunto es el peor
     * entre el suyo y los de sus componentes: no se despliega a medias.
     */
    public function effectiveStatus(): AssetStatus
    {
        return $this->composite_status ?? $this->status;
    }

    public function effectiveExpiresAt(): ?\Illuminate\Support\Carbon
    {
        return $this->composite_expires_at ?? $this->expires_at;
    }

    /**
     * Componentes que están bloqueando al conjunto. Es lo que hay que
     * mostrarle al taller: no alcanza con decir que el conjunto no sirve,
     * hay que decir por cuál de sus piezas.
     *
     * @return \Illuminate\Support\Collection<int, self>
     */
    public function blockingComponents(): \Illuminate\Support\Collection
    {
        return $this->components
            ->filter(fn (self $c) => $c->effectiveStatus()->severity() > AssetStatus::Fit->severity())
            ->sortByDesc(fn (self $c) => $c->effectiveStatus()->severity())
            ->values();
    }

    /** Conjuntos: los que tienen al menos un componente. */
    public function scopeComposite(Builder $query): Builder
    {
        return $query->whereHas('components');
    }
}
