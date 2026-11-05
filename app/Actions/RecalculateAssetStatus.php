<?php

namespace App\Actions;

use App\Enums\AssetStatus;
use App\Enums\InspectionResult;
use App\Enums\ReportStatus;
use App\Models\Asset;
use App\Models\Reception;
use App\Support\RecertificationWindow;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
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
 *  2. La aptitud mira TODAS las certificaciones que dan aptitud, no sólo la
 *     última: por cada tipo de operación (COS anual, COC de conformidad…) vale
 *     su línea más reciente. La pieza está apta sólo si está al día en todas.
 *  3. Cualquier tipo No Apto -> `unfit`, sin importar los vencimientos.
 *  4. Si todas son Apto: manda la que vence primero. Con ese vencimiento,
 *     vencido -> `unfit`; dentro de la ventana preventiva -> `expiring`; en
 *     otro caso -> `fit`.
 *  5. Sin dictamen alguno -> `unknown`.
 *  6. Con vencimiento, se deriva `send_by_at`: la última fecha en la que el
 *     cliente puede despachar el equipo sin que pierda la certificación
 *     mientras está en el taller. Ver RecertificationWindow.
 *  7. Una pieza **rechazada que el cliente decidió no reparar, o que el taller
 *     marcó sin solución**, queda `unfit` y sin vencimiento, aunque su último
 *     FMR dijera Apto. Es una segunda fuente de aptitud, agregada cuando el
 *     negocio definió que ese equipo «se devuelve sin reparar y marcado como
 *     No Apto».
 *
 *     Sin esto el equipo volvía al cliente figurando certificado, que es
 *     justo lo contrario de lo que pasó. No se resolvió escribiéndole el
 *     estado a mano desde la recepción: eso habría durado hasta el siguiente
 *     recálculo, que sólo mira inspecciones. La regla de fondo se mantiene
 *     —la aptitud se deriva de hechos registrados, nunca se asigna—, lo que
 *     cambió es que ahora hay dos clases de hecho que la determinan.
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
        // Todas las líneas determinantes finales, de la más nueva a la más vieja.
        $lineas = DB::table('inspection_report_asset as l')
            ->join('inspection_reports as r', 'r.id', '=', 'l.inspection_report_id')
            ->where('l.asset_id', $asset->getKey())
            ->whereIn('l.result', [InspectionResult::Apto->value, InspectionResult::NoApto->value])
            ->where('r.status', ReportStatus::Final->value)
            ->whereNull('r.deleted_at')
            ->orderByDesc('r.inspected_at')
            ->orderByDesc('l.id')
            ->get(['l.service_id', 'r.service_id as report_service_id', 'l.result', 'l.expires_at', 'l.inspection_report_id', 'r.inspected_at', 'r.finalized_at']);

        // Lo que el equipo ya traía certificado de otra empresa, cargado a mano en
        // su ficha: cuenta como una certificación apta más, sin informe de Odessa.
        $externas = $this->externalCertifications($asset);

        if ($lineas->isEmpty() && $externas->isEmpty()) {
            // Sin ningún informe, un rechazo devuelto sin reparar sigue siendo
            // un hecho: la pieza no está sin dictaminar, está no apta.
            $sinReparar = $this->wasReturnedUnrepairedAfter($asset, null);

            $asset->forceFill([
                'status' => $sinReparar ? AssetStatus::Unfit : AssetStatus::Unknown,
                'expires_at' => null,
                'send_by_at' => null,
                'current_inspection_report_id' => null,
            ])->save();

            return;
        }

        // La inspección determinante más reciente: fija el corte para la regla de
        // "devuelto sin reparar". El informe a mostrar es siempre uno de Odessa.
        $masReciente = $lineas->concat($externas)->sortByDesc('inspected_at')->first();
        $ultimoInforme = $lineas->first()?->inspection_report_id;

        // Una línea por tipo de operación: la más reciente de cada una (vienen
        // ordenadas de nueva a vieja, así que la primera de cada tipo manda).
        // Las que no tienen operación identificable caen todas en un mismo
        // grupo para que igual cuenten. Las cargadas a mano van al final: si
        // Odessa ya certificó esa operación, rige su informe.
        $porTipo = [];
        foreach ($lineas->concat($externas) as $linea) {
            $tipo = $linea->service_id ?? $linea->report_service_id ?? 0;
            $porTipo[$tipo] ??= $linea;
        }
        $vigentes = collect($porTipo);

        // Cualquier certificación No Apta deja la pieza no apta, aunque las
        // demás estén al día.
        $hayNoApto = $vigentes->contains(fn ($l) => $l->result === InspectionResult::NoApto->value);

        if ($hayNoApto) {
            $status = AssetStatus::Unfit;
            $vence = null;
        } else {
            // Todas aptas: manda la que vence primero. Una sin fecha no acota.
            $determinante = $vigentes
                ->sortBy(fn ($l) => $l->expires_at ?? '9999-12-31')
                ->first();
            $vence = $determinante->expires_at ? Carbon::parse($determinante->expires_at) : null;
            $status = $this->resolveStatus(InspectionResult::Apto, $vence);
        }

        /*
         * Un rechazo que el cliente no quiso reparar pesa más que la
         * inspección anterior, si es posterior a ella. El equipo volvió al
         * cliente sin arreglar: no puede seguir figurando certificado.
         */
        if ($this->wasReturnedUnrepairedAfter($asset, $this->cutoff($masReciente))) {
            $asset->forceFill([
                'status' => AssetStatus::Unfit,
                'expires_at' => null,
                'send_by_at' => null,
                // El informe sigue siendo el último dictamen formal: se conserva
                // para que la ficha no pierda el rastro de dónde viene.
                'current_inspection_report_id' => $ultimoInforme,
            ])->save();

            return;
        }

        $asset->forceFill([
            'status' => $status,
            'expires_at' => $vence,
            // Derivada de los vencimientos: última fecha en la que el cliente
            // puede despachar el equipo y todavía recibirlo certificado. Cada
            // certificación cuenta con los días de taller de su servicio y vale
            // la más próxima. Sin fecha o ya no apto, no hay plazo que derivar.
            'send_by_at' => $status === AssetStatus::Unfit ? null : $this->sendBy($asset, $vigentes),
            // El informe determinante es el último dictamen formal, que es lo
            // que la ficha rotula "último informe con dictamen".
            'current_inspection_report_id' => $ultimoInforme,
        ])->save();
    }

    /**
     * Certificaciones que el equipo traía de otra empresa, con la misma forma
     * que las líneas de informe para que el cálculo las trate igual.
     *
     * @return Collection<int, object>
     */
    private function externalCertifications(Asset $asset): Collection
    {
        return DB::table('asset_external_certifications')
            ->where('asset_id', $asset->getKey())
            ->get(['service_id', 'certified_at', 'expires_at'])
            ->map(fn ($e) => (object) [
                'service_id' => $e->service_id,
                'report_service_id' => null,
                'result' => InspectionResult::Apto->value,
                'expires_at' => $e->expires_at,
                'inspection_report_id' => null,
                'inspected_at' => $e->certified_at,
                'finalized_at' => null,
            ]);
    }

    /**
     * Desde cuándo cuenta una inspección para ordenarla contra un rechazo.
     *
     * `inspected_at` es un DÍA y el rechazo un instante: el mismo día, el
     * rechazo caía siempre después de las 00:00 y le ganaba a un informe
     * cargado después (caso «Puente 4», 04/11/2026). Si el informe se cerró ese
     * mismo día vale la hora en que se cerró; si se cerró otro día (uno viejo
     * cargado tarde) se mantiene el día de la inspección.
     */
    private function cutoff(object $linea): string
    {
        $finalizado = $linea->finalized_at;

        return $finalizado && substr((string) $finalizado, 0, 10) === substr((string) $linea->inspected_at, 0, 10)
            ? (string) $finalizado
            : (string) $linea->inspected_at;
    }

    /**
     * ¿Se devolvió sin reparar después de la fecha dada?
     *
     * Se compara contra la inspección vigente porque el orden importa: si
     * después de devolverla el cliente la volvió a mandar y esta vez aprobó,
     * manda la inspección nueva y la pieza vuelve a estar apta. El rechazo no
     * la condena para siempre, sólo hasta el próximo dictamen favorable.
     *
     * El momento decisivo es cuándo quedó resuelto que el equipo no se iba a
     * arreglar —el cliente contestó que no, o el taller lo marcó sin
     * solución—, no cuándo se despachó.
     */
    private function wasReturnedUnrepairedAfter(Asset $asset, ?string $since): bool
    {
        return DB::table('receptions')
            ->where('asset_id', $asset->getKey())
            ->whereNull('deleted_at')
            ->where(fn ($q) => $q
                ->where(fn ($d) => $d
                    ->where('repair_decision', Reception::REPAIR_DECLINED)
                    ->when($since !== null, fn ($w) => $w->where('repair_decided_at', '>=', $since)))
                ->orWhere(fn ($u) => $u
                    ->whereNotNull('unrepairable_at')
                    ->when($since !== null, fn ($w) => $w->where('unrepairable_at', '>=', $since))))
            ->exists();
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

        /*
         * Un set se arma con piezas que se inspeccionan una por una; él mismo no
         * suele tener dictamen. Su «sin inspección» propio no cuenta: si contara,
         * pesaría más que sus piezas aptas y el set no estaría listo nunca. Si
         * tiene dictamen propio, sí cuenta, como en cualquier conjunto.
         */
        $propio = ($asset->is_set && $asset->status === AssetStatus::Unknown) ? null : $asset->status;
        $status = $propio;
        $expiresAt = $asset->expires_at;

        foreach ($components as $component) {
            $status = $status === null
                ? $component->effectiveStatus()
                : $status->worstWith($component->effectiveStatus());

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
     * Fecha límite de envío: la más próxima entre las certificaciones vigentes.
     *
     * Cada una (COS, COC…) resta a su vencimiento los días de taller de SU
     * servicio y el margen. La más próxima no es siempre la que vence primero:
     * una certificación que vence más tarde pero tarda mucho más en hacerse
     * puede obligar a mandar antes el equipo. Una sin fecha no acota.
     *
     * @param  Collection<int, object>  $vigentes  Una línea por servicio, por id de servicio.
     */
    private function sendBy(Asset $asset, Collection $vigentes): ?Carbon
    {
        $conFecha = $vigentes->filter(fn ($l) => $l->expires_at !== null);

        if ($conFecha->isEmpty()) {
            return null;
        }

        $diasPorServicio = DB::table('services')
            ->whereIn('id', $conFecha->keys()->filter()->all())
            ->pluck('turnaround_days', 'id');
        $diasDelTipo = $this->typeTurnaround($asset);
        $margen = (int) config('odessa.recertification.safety_margin_days');

        return $conFecha
            ->map(fn ($l, $servicioId) => (new RecertificationWindow(
                expiresAt: Carbon::parse($l->expires_at),
                turnaroundDays: RecertificationWindow::turnaround($diasPorServicio[$servicioId] ?? null, $diasDelTipo),
                safetyMarginDays: $margen,
            ))->sendBy())
            ->sortBy(fn (Carbon $fecha) => $fecha->getTimestamp())
            ->first();
    }

    /**
     * Días de taller que cargó el negocio para el tipo de equipo, o null.
     *
     * Se consulta la columna directamente en vez de navegar la relación: este
     * recálculo corre en lote sobre miles de piezas y con la relación sin
     * cargar provocaría una consulta por pieza —o directamente una excepción,
     * porque la carga perezosa está deshabilitada fuera de producción—.
     */
    private function typeTurnaround(Asset $asset): ?int
    {
        if ($asset->catalog_item_id === null) {
            return null;
        }

        if ($asset->relationLoaded('catalogItem')) {
            return $asset->catalogItem?->turnaround_days;
        }

        $dias = DB::table('catalog_items')->where('id', $asset->catalog_item_id)->value('turnaround_days');

        return $dias === null ? null : (int) $dias;
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
