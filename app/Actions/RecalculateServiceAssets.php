<?php

namespace App\Actions;

use App\Enums\ReportStatus;
use App\Models\Asset;
use App\Models\Service;
use Illuminate\Support\Facades\DB;

/**
 * Vuelve a calcular las piezas que tienen dictámenes finales de una operación
 * (o de todas).
 *
 * `send_by_at` se guarda en cada pieza, así que si cambian los días de taller de
 * un servicio la fecha guardada queda vieja hasta el próximo dictamen. Se corre
 * al editar esos días en Admin → Operaciones y, una vez, al desplegar el cambio
 * de regla (`odessa:recalcular-fechas-envio`).
 *
 * Es idempotente y sólo escribe lo derivado: estado, vencimiento y fecha límite.
 */
class RecalculateServiceAssets
{
    public function __construct(private RecalculateAssetStatus $recalculate) {}

    /** @return int Cuántas piezas se recalcularon. */
    public function __invoke(?Service $service = null): int
    {
        $ids = DB::table('inspection_report_asset as l')
            ->join('inspection_reports as r', 'r.id', '=', 'l.inspection_report_id')
            ->where('r.status', ReportStatus::Final->value)
            ->whereNull('r.deleted_at')
            // Vale la operación de la línea; si no tiene, la del informe.
            ->when($service, fn ($q) => $q->whereRaw('coalesce(l.service_id, r.service_id) = ?', [$service->getKey()]))
            ->distinct()
            ->pluck('l.asset_id')
            // También las que traen esa certificación cargada a mano (de otra
            // empresa): sus días de taller también cuentan para el plazo.
            ->merge(DB::table('asset_external_certifications')
                ->when($service, fn ($q) => $q->where('service_id', $service->getKey()))
                ->pluck('asset_id'))
            ->unique()
            ->values()
            ->all();

        $hechas = 0;

        foreach (array_chunk($ids, 500) as $lote) {
            // Sin el alcance por empresa: corre por consola o en nombre de un
            // administrador y tiene que ver las piezas de todas.
            foreach (Asset::withoutGlobalScope('organization')->whereIn('id', $lote)->get() as $pieza) {
                ($this->recalculate)($pieza);
                $hechas++;
            }
        }

        return $hechas;
    }
}
