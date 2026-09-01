<?php

namespace App\Actions;

use App\Enums\AssetStatus;
use App\Enums\InspectionResult;
use App\Enums\ReportStatus;
use App\Models\Asset;
use App\Support\RecertificationWindow;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Recalcula el estado de aptitud de una pieza a partir de su historial de FMR.
 *
 * Reglas derivadas de la auditoría:
 *
 *  1. Sólo cuentan las líneas de FMR **finales** con resultado Apto o No Apto.
 *     Las líneas "No aplica" y "Sin procesar" quedan en la bitácora pero no
 *     alteran el estado. El sistema legacy lo indica de forma explícita en el
 *     detalle de cada pieza.
 *  2. Manda la línea más reciente por fecha de inspección; ante empate, la de
 *     mayor id (última cargada).
 *  3. Si el dictamen es No Apto -> `unfit`, sin importar el vencimiento.
 *  4. Si es Apto: vencido -> `unfit`; dentro de la ventana preventiva ->
 *     `expiring`; en otro caso -> `fit`.
 *  5. Sin dictamen alguno -> `unknown`.
 *  6. Con vencimiento, se deriva `send_by_at`: la última fecha en la que el
 *     cliente puede despachar el equipo sin que pierda la certificación
 *     mientras está en el taller. Ver RecertificationWindow.
 *
 * Es idempotente: se puede ejecutar cuantas veces haga falta.
 */
class RecalculateAssetStatus
{
    public function __invoke(Asset $asset): Asset
    {
        $this->resolveOwnStatus($asset);

        // El conjunto depende de sus piezas, y una pieza afecta a su conjunto:
        // hay que propagar en las dos direcciones desde el punto que cambió.
        $this->resolveCompositeStatus($asset);
        $this->cascadeToParent($asset);

        return $asset;
    }

    /** Estado que surge del propio historial de inspecciones de la pieza. */
    private function resolveOwnStatus(Asset $asset): void
    {
        $line = DB::table('inspection_report_asset as l')
            ->join('inspection_reports as r', 'r.id', '=', 'l.inspection_report_id')
            ->where('l.asset_id', $asset->getKey())
            ->whereIn('l.result', [InspectionResult::Apto->value, InspectionResult::NoApto->value])
            ->where('r.status', ReportStatus::Final->value)
            ->whereNull('r.deleted_at')
            ->orderByDesc('r.inspected_at')
            ->orderByDesc('l.id')
            ->select('l.id', 'l.result', 'l.expires_at', 'l.inspection_report_id')
            ->first();

        if ($line === null) {
            $asset->forceFill([
                'status' => AssetStatus::Unknown,
                'expires_at' => null,
                'send_by_at' => null,
                'current_inspection_report_id' => null,
            ])->save();

            return;
        }

        $expiresAt = $line->expires_at ? Carbon::parse($line->expires_at) : null;

        $status = $this->resolveStatus(InspectionResult::from($line->result), $expiresAt);

        $asset->forceFill([
            'status' => $status,
            'expires_at' => $expiresAt,
            // Derivada del vencimiento: última fecha en la que el cliente puede
            // despachar el equipo y todavía recibirlo certificado. Se calcula
            // sobre `$expiresAt` y no sobre el modelo, que en este punto
            // todavía tiene el vencimiento anterior.
            'send_by_at' => $this->sendBy($asset, $expiresAt),
            'current_inspection_report_id' => $line->inspection_report_id,
        ])->save();
    }

    /**
     * Estado efectivo del conjunto: el peor entre el propio y los de sus
     * componentes, y el vencimiento más próximo entre todos.
     *
     * Una pieza suelta no tiene componentes, así que queda en null y se usa
     * su estado propio. Distinguir null de "igual al propio" importa: permite
     * saber si un equipo es compuesto sin volver a consultar.
     */
    private function resolveCompositeStatus(Asset $asset): void
    {
        $components = $asset->components()->get(['id', 'status', 'composite_status', 'expires_at', 'composite_expires_at']);

        if ($components->isEmpty()) {
            $asset->forceFill([
                'composite_status' => null,
                'composite_expires_at' => null,
            ])->save();

            return;
        }

        $status = $asset->status;
        $expiresAt = $asset->expires_at;

        foreach ($components as $component) {
            $status = $status->worstWith($component->effectiveStatus());

            $componentExpiry = $component->effectiveExpiresAt();

            // El vencimiento del conjunto es el primero que caiga: es la fecha
            // a partir de la cual ya no se puede desplegar completo.
            if ($componentExpiry !== null && ($expiresAt === null || $componentExpiry->lt($expiresAt))) {
                $expiresAt = $componentExpiry;
            }
        }

        $asset->forceFill([
            'composite_status' => $status,
            'composite_expires_at' => $expiresAt,
        ])->save();
    }

    /**
     * Un componente que cambia obliga a recalcular su conjunto, y así hacia
     * arriba. Se recorre con un tope de profundidad para que un dato mal
     * cargado —una pieza que termine siendo su propia antecesora— no deje el
     * proceso girando para siempre.
     */
    private function cascadeToParent(Asset $asset): void
    {
        $current = $asset;
        $depth = 0;

        while ($current->parent_id !== null && $depth < 10) {
            $parent = Asset::withoutGlobalScopes()->find($current->parent_id);

            if ($parent === null) {
                return;
            }

            $this->resolveCompositeStatus($parent);

            $current = $parent;
            $depth++;
        }
    }

    /**
     * Fecha límite de envío para el vencimiento que se acaba de fijar.
     *
     * El tiempo de reparación sale del tipo de equipo cuando tiene uno propio;
     * el margen de seguridad es de la operación.
     */
    private function sendBy(Asset $asset, ?Carbon $expiresAt): ?Carbon
    {
        if ($expiresAt === null) {
            return null;
        }

        return (new RecertificationWindow(
            expiresAt: $expiresAt,
            turnaroundDays: $this->turnaroundDays($asset),
            safetyMarginDays: (int) config('odessa.recertification.safety_margin_days'),
        ))->sendBy();
    }

    /**
     * Días de taller para el tipo de equipo de esta pieza.
     *
     * Se consulta la columna directamente en vez de navegar la relación: este
     * recálculo corre en lote sobre miles de piezas y con la relación sin
     * cargar provocaría una consulta por pieza —o directamente una excepción,
     * porque la carga perezosa está deshabilitada fuera de producción—.
     */
    private function turnaroundDays(Asset $asset): int
    {
        $default = (int) config('odessa.recertification.turnaround_days');

        if ($asset->catalog_item_id === null) {
            return $default;
        }

        if ($asset->relationLoaded('catalogItem')) {
            return $asset->catalogItem?->turnaroundDays() ?? $default;
        }

        return (int) (DB::table('catalog_items')
            ->where('id', $asset->catalog_item_id)
            ->value('turnaround_days') ?? $default);
    }

    private function resolveStatus(InspectionResult $result, ?Carbon $expiresAt): AssetStatus
    {
        if ($result === InspectionResult::NoApto) {
            return AssetStatus::Unfit;
        }

        // Apto sin fecha de vencimiento: se considera vigente sin caducidad conocida.
        if ($expiresAt === null) {
            return AssetStatus::Fit;
        }

        if ($expiresAt->isBefore(today())) {
            return AssetStatus::Unfit;
        }

        $warningDays = (int) config('odessa.expiry_warning_days');

        if ($expiresAt->lessThanOrEqualTo(today()->addDays($warningDays))) {
            return AssetStatus::Expiring;
        }

        return AssetStatus::Fit;
    }
}
