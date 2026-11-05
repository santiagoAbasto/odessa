<?php

namespace App\Support;

use App\Enums\InspectionResult;
use App\Enums\ReportStatus;
use App\Models\Asset;
use App\Models\Service;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Certificaciones de un equipo y su vencimiento más próximo, SIN depender del
 * estado.
 *
 * Un equipo puede tener dos plazos a la vez (COS anual, COC de conformidad…).
 * Para cada operación que da aptitud vale su línea final más reciente, y el
 * vencimiento del equipo es el más próximo de las que están aptas.
 *
 * Por qué no alcanza con `assets.expires_at`: ese campo es el ESTADO derivado,
 * y queda vacío cuando el equipo no está apto aunque una de sus certificaciones
 * siga vigente. Las planillas que pidió el negocio (04/11/2026) tienen que
 * mostrar el vencimiento «independiente del estado» y el detalle de cada
 * certificación, así que se calcula de las inspecciones, no del estado.
 *
 * Es de sólo lectura y no cambia cómo se calcula la aptitud
 * (RecalculateAssetStatus). Se resuelve por tandas —una consulta para todos los
 * equipos que se le pasen— porque lo usan listados de miles de equipos.
 */
class AssetCertificationSummary
{
    /** @var Collection<int, Service>|null */
    private ?Collection $types = null;

    /**
     * Operaciones que dan aptitud (COS, COC…), por id, en orden estable.
     *
     * @return Collection<int, Service>
     */
    public function types(): Collection
    {
        return $this->types ??= Service::query()
            ->where('determines_fitness', true)
            ->orderBy('id')
            ->get(['id', 'code', 'name', 'turnaround_days'])
            ->keyBy('id');
    }

    /**
     * @param  iterable<Asset>  $assets  Una tanda de equipos.
     * @return array<int, array{
     *     certifications: array<int, array<string, mixed>>,
     *     nextExpiry: ?Carbon,
     *     nextType: ?string,
     *     nextSendBy: ?Carbon,
     * }> Por id de equipo. `certifications` va por id de operación, el que
     *    vence antes primero; los equipos sin ninguna dan listas vacías.
     */
    public function forAssets(iterable $assets): array
    {
        $assets = collect($assets)->keyBy(fn (Asset $a) => $a->getKey());
        $types = $this->types();

        $porEquipo = [];

        if ($assets->isNotEmpty() && $types->isNotEmpty()) {
            $lineas = DB::table('inspection_report_asset as l')
                ->join('inspection_reports as r', 'r.id', '=', 'l.inspection_report_id')
                ->whereIn('l.asset_id', $assets->keys()->all())
                ->where('r.status', ReportStatus::Final->value)
                ->whereNull('r.deleted_at')
                ->whereIn('l.result', [InspectionResult::Apto->value, InspectionResult::NoApto->value])
                // El mismo orden con el que RecalculateAssetStatus decide quién vale.
                ->orderByDesc('r.inspected_at')
                ->orderByDesc('l.id')
                ->get(['l.asset_id', 'l.service_id', 'r.service_id as report_service_id', 'l.result', 'l.expires_at', 'r.inspected_at']);

            foreach ($lineas as $linea) {
                // Vale la operación de la línea; si no tiene, la del informe.
                $serviceId = $linea->service_id ?? $linea->report_service_id;

                // Sólo las que dan aptitud, y una sola —la más reciente— por tipo.
                if ($serviceId === null || ! $types->has($serviceId) || isset($porEquipo[$linea->asset_id][$serviceId])) {
                    continue;
                }

                $servicio = $types->get($serviceId);

                $porEquipo[$linea->asset_id][$serviceId] = [
                    'code' => $servicio->code,
                    'name' => $servicio->name,
                    'result' => InspectionResult::from($linea->result)->label(),
                    'isApt' => $linea->result === InspectionResult::Apto->value,
                    'expiresAt' => $linea->expires_at ? Carbon::parse($linea->expires_at)->toDateString() : null,
                    'inspectedAt' => $linea->inspected_at ? Carbon::parse($linea->inspected_at)->toDateString() : null,
                    'external' => false,
                ];
            }

            // Las que el equipo ya traía de otra empresa, cargadas a mano en su
            // ficha. Valen sólo donde Odessa todavía no certificó esa operación:
            // si lo hizo, rige su informe (misma regla que RecalculateAssetStatus).
            $externas = DB::table('asset_external_certifications')
                ->whereIn('asset_id', $assets->keys()->all())
                ->get(['id', 'asset_id', 'service_id', 'certified_by', 'certificate_number', 'certified_at', 'expires_at']);

            foreach ($externas as $e) {
                if (! $types->has($e->service_id) || isset($porEquipo[$e->asset_id][$e->service_id])) {
                    continue;
                }

                $servicio = $types->get($e->service_id);

                $porEquipo[$e->asset_id][$e->service_id] = [
                    'code' => $servicio->code,
                    'name' => $servicio->name,
                    'result' => InspectionResult::Apto->label(),
                    'isApt' => true,
                    'expiresAt' => Carbon::parse($e->expires_at)->toDateString(),
                    'inspectedAt' => Carbon::parse($e->certified_at)->toDateString(),
                    'external' => true,
                    'externalId' => $e->id,
                    'serviceId' => $e->service_id,
                    'certifiedBy' => $e->certified_by,
                    'certificateNumber' => $e->certificate_number,
                ];
            }
        }

        // La fecha límite de un conjunto también la acota su componente más
        // apurado: se toma la ya calculada en cada componente (por servicio).
        // ponytail: sólo hijos directos; un set dentro de otro set no propaga su
        // plazo hacia arriba. Subir un nivel más cuando haga falta.
        $hijos = $assets->isEmpty() ? collect() : DB::table('assets')
            ->whereIn('parent_id', $assets->keys()->all())
            ->whereNull('deleted_at')
            ->whereNotNull('send_by_at')
            ->selectRaw('parent_id, min(send_by_at) as primero')
            ->groupBy('parent_id')
            ->pluck('primero', 'parent_id');
        $margen = (int) config('odessa.recertification.safety_margin_days');

        $resumen = [];

        foreach ($assets as $id => $asset) {
            // El más próximo a vencer primero; los sin fecha, al final.
            $certificaciones = collect($porEquipo[$id] ?? [])
                ->sortBy(fn (array $c) => $c['expiresAt'] ?? '9999-12-31')
                ->all();

            // Sólo cuentan las que están aptas: una no apta no «vence», fracasó.
            $proxima = collect($certificaciones)->first(fn (array $c) => $c['isApt'] && $c['expiresAt'] !== null);
            $vence = $proxima ? Carbon::parse($proxima['expiresAt']) : null;
            $tipo = $proxima['code'] ?? null;

            // «Mandar antes de»: cada certificación apta resta a su vencimiento los
            // días de taller de SU servicio (o los del tipo de equipo, o el valor
            // por defecto) y el margen; vale la más próxima. Es la misma regla
            // que guarda RecalculateAssetStatus en `send_by_at`.
            $diasDelTipo = $asset->catalogItem?->turnaround_days;
            $enviarAntes = collect($certificaciones)
                ->filter(fn (array $c) => $c['isApt'] && $c['expiresAt'] !== null)
                ->map(fn (array $c, int $servicioId) => (new RecertificationWindow(
                    expiresAt: Carbon::parse($c['expiresAt']),
                    turnaroundDays: RecertificationWindow::turnaround($types->get($servicioId)?->turnaround_days, $diasDelTipo),
                    safetyMarginDays: $margen,
                ))->sendBy())
                ->sortBy(fn (Carbon $fecha) => $fecha->getTimestamp())
                ->first();

            if (($hijo = $hijos[$id] ?? null) !== null) {
                $limiteHijo = Carbon::parse($hijo)->startOfDay();
                $enviarAntes = $enviarAntes === null || $limiteHijo->lt($enviarAntes) ? $limiteHijo : $enviarAntes;
            }

            // Un conjunto vence cuando vence su componente más próximo.
            if ($asset->composite_expires_at !== null && ($vence === null || $asset->composite_expires_at->lt($vence))) {
                $vence = $asset->composite_expires_at->copy();
                $tipo = 'Componentes';
            }

            $resumen[$id] = ['certifications' => $certificaciones, 'nextExpiry' => $vence, 'nextType' => $tipo, 'nextSendBy' => $enviarAntes];
        }

        return $resumen;
    }

    /**
     * Encabezados de las columnas por tipo de certificación, para planillas.
     * Tres por tipo —cuándo se inspeccionó, cuándo vence y con qué resultado—:
     * sin el resultado, una fecha vacía no distingue «nunca se hizo» de «salió
     * no apto».
     *
     * @return list<string>
     */
    public function headings(): array
    {
        return $this->types()->flatMap(fn (Service $t) => [
            "{$t->code} · Fecha de inspección",
            "{$t->code} · Vence",
            "{$t->code} · Resultado",
        ])->values()->all();
    }

    /**
     * Las celdas de ese equipo, en el mismo orden que `headings()`.
     *
     * @param  array<int, array<string, mixed>>  $certifications  Las de `forAssets()`, por id de operación.
     * @return list<?string>
     */
    public function cells(array $certifications): array
    {
        $fecha = fn (?string $f) => $f === null ? null : Carbon::parse($f)->format('d/m/Y');
        $celdas = [];

        foreach ($this->types() as $id => $tipo) {
            $c = $certifications[$id] ?? null;
            // La cargada a mano no es un informe de Odessa: la planilla lo aclara.
            $resultado = isset($c['result']) ? $c['result'].(($c['external'] ?? false) ? ' (otra empresa)' : '') : null;
            array_push($celdas, $fecha($c['inspectedAt'] ?? null), $fecha($c['expiresAt'] ?? null), $resultado);
        }

        return $celdas;
    }
}
