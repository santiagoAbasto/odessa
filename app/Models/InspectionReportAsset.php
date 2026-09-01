<?php

namespace App\Models;

use App\Enums\InspectionResult;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Línea de un FMR: el dictamen de UNA pieza dentro de UN informe.
 *
 * Es el registro que da trazabilidad. El estado de la pieza siempre se puede
 * reconstruir desde aquí, sin depender de las columnas desnormalizadas de `assets`.
 */
class InspectionReportAsset extends Pivot
{
    protected $table = 'inspection_report_asset';

    public $incrementing = true;

    protected $fillable = [
        'inspection_report_id', 'asset_id', 'service_id', 'result', 'expires_at', 'seal', 'findings',
    ];

    protected function casts(): array
    {
        return [
            'result' => InspectionResult::class,
            'expires_at' => 'date',
        ];
    }

    public function inspectionReport(): BelongsTo
    {
        return $this->belongsTo(InspectionReport::class);
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
