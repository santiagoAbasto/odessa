<?php

namespace App\Http\Controllers;

use App\Enums\AssetStatus;
use App\Models\Asset;
use App\Models\InspectionReport;
use App\Models\Organization;
use App\Models\Reception;
use App\Support\Permissions;
use App\Support\SpreadsheetExport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Reporte por organización: status, vencimientos y trabajos.
 *
 * Lo pidió el negocio en pantalla y en Excel, y con los trabajos como
 * recepciones más informes FMR emitidos. Las dos salidas usan las mismas
 * consultas para que la planilla nunca diga algo distinto de la pantalla.
 *
 * El aislamiento es el de siempre: el scope de organización hace que un
 * cliente sólo alcance lo suyo, mande lo que mande en el filtro.
 */
class OrganizationReportController extends Controller
{
    /** Tramos de vencimiento, en días. Acumulados: el de 60 incluye al de 30. */
    private const WINDOWS = [30, 60, 90];

    /** Filas por listado en pantalla. El Excel lleva todas. */
    private const SCREEN_LIMIT = 100;

    public function index(Request $request): Response
    {
        [$orgId, $desde, $hasta] = $this->params($request);
        $user = $request->user();

        return Inertia::render('reports-organization/index', [
            'report' => $this->build($orgId, $desde, $hasta),
            'filters' => [
                'organization' => $user->isClientUser() ? null : $request->query('organization'),
                'desde' => $desde->toDateString(),
                'hasta' => $hasta->toDateString(),
            ],
            'organizationOptions' => $user->isClientUser() ? [] : Organization::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['public_id', 'name'])
                ->map(fn (Organization $o) => ['value' => $o->public_id, 'label' => $o->name])
                ->all(),
            'can' => ['export' => $user->can(Permissions::REPORTS_EXPORT)],
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        abort_unless($request->user()->can(Permissions::REPORTS_EXPORT), 403);

        [$orgId, $desde, $hasta] = $this->params($request);
        $user = $request->user();
        $report = $this->build($orgId, $desde, $hasta);

        $empresa = $user->isClientUser()
            ? $user->organization?->name
            : ($orgId ? Organization::query()->whereKey($orgId)->value('name') : 'todas');

        $resumen = [
            ['Organización', $empresa ?? 'todas'],
            ['Período de trabajos', $desde->format('d/m/Y').' al '.$hasta->format('d/m/Y')],
            ['Equipos', $report['status']['total']],
            ...collect($report['status']['labels'])->map(fn ($s, $key) => ["Estado · {$s['label']}", $report['status']['counts'][$key]])->values(),
            ...collect($report['expiry']['windows'])->map(fn ($n, $d) => ["Vencen en {$d} días", $n])->values(),
            ['Fuera de tiempo para mandarlos', $report['expiry']['sendDeadlineMissed']],
            ['En taller ahora · pendientes', $report['work']['now']['pending']],
            ['En taller ahora · en proceso', $report['work']['now']['in_progress']],
            ['En taller ahora · rechazadas', $report['work']['now']['rejected']],
            ['En taller ahora · esperando al cliente', $report['work']['now']['awaiting_client']],
            ['En taller ahora · reparación', $report['work']['now']['repair']],
            ['En taller ahora · listas para despachar', $report['work']['now']['ready']],
            ['Período · recibidos', $report['work']['period']['received']],
            ['Período · despachados', $report['work']['period']['dispatched']],
            ['Período · devueltos sin reparar', $report['work']['period']['returnedUnrepaired']],
            ['Período · FMR emitidos', $report['work']['period']['reportsIssued']],
        ];

        return SpreadsheetExport::downloadSheets(
            filename: SpreadsheetExport::filename('reporte', $empresa),
            sheets: [
                ['name' => 'Resumen', 'headings' => ['Indicador', 'Valor'], 'rows' => fn () => $resumen],
                [
                    'name' => 'Vencimientos',
                    'headings' => ['Equipo', 'Serie', 'Código', 'Empresa', 'Estado', 'Vence', 'Mandar antes de', 'Días para vencer', 'Fuera de tiempo'],
                    'rows' => fn () => $this->expiryQuery($orgId)->lazy(500)->map(fn (Asset $a) => [
                        $a->displayName(), $a->serial_number, $a->code, $a->organization?->name,
                        $a->status->label(), $a->expires_at?->format('d/m/Y'), $a->send_by_at?->format('d/m/Y'),
                        (int) today()->diffInDays($a->expires_at, false),
                        $this->missed($a) ? 'Sí' : 'No',
                    ]),
                ],
                [
                    'name' => 'Trabajos',
                    'headings' => ['Equipo', 'Serie', 'Empresa', 'Ingreso', 'Etapa', 'Despachado'],
                    'rows' => fn () => $this->receptionsQuery($orgId, $desde, $hasta)->lazy(500)->map(fn (Reception $r) => [
                        $r->asset?->displayName(), $r->asset?->serial_number, $r->asset?->organization?->name,
                        $r->received_at?->format('d/m/Y'), $r->stageLabel(), $r->dispatched_at?->format('d/m/Y'),
                    ]),
                ],
                [
                    'name' => 'FMR emitidos',
                    'headings' => ['Número', 'Fecha', 'Equipo', 'Piezas', 'Empresa'],
                    'rows' => fn () => $this->reportsQuery($orgId, $desde, $hasta)->lazy(500)->map(fn (InspectionReport $r) => [
                        $r->number, $r->inspected_at?->format('d/m/Y'), $r->equipmentName(), $r->assets_count, $r->organization?->name,
                    ]),
                ],
            ],
        );
    }

    /**
     * Organización y período pedidos.
     *
     * Un cliente no elige organización: la ignora y lo acota el scope. Las
     * fechas mal formadas caen al período por defecto en lugar de romper la
     * pantalla, que es de consulta.
     *
     * @return array{0: int|null, 1: Carbon, 2: Carbon}
     */
    private function params(Request $request): array
    {
        $orgId = $request->user()->isClientUser() || blank($request->query('organization'))
            ? null
            : Organization::query()->where('public_id', $request->query('organization'))->value('id');

        $hasta = rescue(fn () => Carbon::parse((string) $request->query('hasta')), today(), false);
        $hasta = $request->filled('hasta') ? $hasta : today();
        $desde = $request->filled('desde')
            ? rescue(fn () => Carbon::parse((string) $request->query('desde')), today()->subDays(90), false)
            : today()->subDays(90);

        if ($desde->gt($hasta)) {
            [$desde, $hasta] = [$hasta, $desde];
        }

        return [$orgId, $desde->startOfDay(), $hasta->endOfDay()];
    }

    /** @return array<string, mixed> */
    private function build(?int $orgId, Carbon $desde, Carbon $hasta): array
    {
        $assets = fn () => $this->forOrg(Asset::query(), $orgId);
        $receptions = fn () => $this->forOrg(Reception::query(), $orgId);

        $counts = $assets()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        $despachadasEnPeriodo = fn () => $receptions()->whereBetween('dispatched_at', [$desde, $hasta]);

        return [
            'status' => [
                'total' => (int) $counts->sum(),
                'counts' => collect(AssetStatus::cases())
                    ->mapWithKeys(fn (AssetStatus $s) => [$s->value => (int) ($counts[$s->value] ?? 0)])->all(),
                'labels' => collect(AssetStatus::cases())
                    ->mapWithKeys(fn (AssetStatus $s) => [$s->value => ['label' => $s->label(), 'tone' => $s->tone()]])->all(),
            ],
            'expiry' => [
                'windows' => collect(self::WINDOWS)->mapWithKeys(fn (int $d) => [
                    (string) $d => $this->certified($assets())->expiringWithin($d)->count(),
                ])->all(),
                // Todavía certificados, pero ya no llegan a recertificarse a
                // tiempo: es la cifra que el negocio más quiere ver.
                'sendDeadlineMissed' => $this->certified($assets())
                    ->whereDate('expires_at', '>=', today())->sendDeadlineMissed()->count(),
                'items' => $this->expiryQuery($orgId)->limit(self::SCREEN_LIMIT)->get()->map(fn (Asset $a) => [
                    'id' => $a->public_id,
                    'name' => $a->displayName(),
                    'serialNumber' => $a->serial_number,
                    'organization' => $a->organization?->name,
                    'statusLabel' => $a->status->label(),
                    'statusTone' => $a->status->tone(),
                    'expiresAt' => $a->expires_at?->toDateString(),
                    'sendByAt' => $a->send_by_at?->toDateString(),
                    'daysLeft' => (int) today()->diffInDays($a->expires_at, false),
                    'missed' => $this->missed($a),
                ])->all(),
            ],
            'work' => [
                'now' => [
                    'in_workshop' => $receptions()->inWorkshop()->count(),
                    'pending' => $receptions()->pending()->count(),
                    'in_progress' => $receptions()->inProgress()->count(),
                    'rejected' => $receptions()->rejected()->count(),
                    'awaiting_client' => $receptions()->awaitingClient()->count(),
                    'repair' => $receptions()->repairQueue()->count(),
                    'ready' => $receptions()->readyToDispatch()->count(),
                ],
                'period' => [
                    'received' => $receptions()->whereBetween('received_at', [$desde->toDateString(), $hasta->toDateString()])->count(),
                    'dispatched' => $despachadasEnPeriodo()->count(),
                    'returnedUnrepaired' => $despachadasEnPeriodo()
                        ->where(fn (Builder $q) => $q
                            ->where('repair_decision', Reception::REPAIR_DECLINED)
                            ->orWhereNotNull('unrepairable_at'))
                        ->count(),
                    'reportsIssued' => $this->reportsQuery($orgId, $desde, $hasta)->count(),
                ],
                'receptions' => $this->receptionsQuery($orgId, $desde, $hasta)->limit(self::SCREEN_LIMIT)->get()
                    ->map(fn (Reception $r) => [
                        'id' => $r->public_id,
                        'url' => $r->url(),
                        'asset' => $r->asset?->displayName(),
                        'serialNumber' => $r->asset?->serial_number,
                        'organization' => $r->asset?->organization?->name,
                        'receivedAt' => $r->received_at?->toDateString(),
                        'dispatchedAt' => $r->dispatched_at?->toDateString(),
                        'stageLabel' => $r->stageLabel(),
                        'stageTone' => $r->stageTone(),
                    ])->all(),
                'reports' => $this->reportsQuery($orgId, $desde, $hasta)->limit(self::SCREEN_LIMIT)->get()
                    ->map(fn (InspectionReport $r) => [
                        'id' => $r->public_id,
                        'number' => $r->number,
                        'inspectedAt' => $r->inspected_at?->toDateString(),
                        'equipment' => $r->equipmentName(),
                        'assetsCount' => $r->assets_count,
                        'organization' => $r->organization?->name,
                    ])->all(),
            ],
        ];
    }

    private function forOrg(Builder $query, ?int $orgId): Builder
    {
        return $orgId === null ? $query : $query->where($query->getModel()->qualifyColumn('organization_id'), $orgId);
    }

    /** Sólo los que hoy figuran certificados: los no aptos no "vencen". */
    private function certified(Builder $query): Builder
    {
        return $query->whereIn('status', [AssetStatus::Fit->value, AssetStatus::Expiring->value]);
    }

    /** Los que vencen dentro del tramo más largo, primero los que hay que mandar antes. */
    private function expiryQuery(?int $orgId): Builder
    {
        return $this->certified($this->forOrg(Asset::query(), $orgId))
            ->expiringWithin(max(self::WINDOWS))
            ->with(['organization:id,name', 'catalogItem:id,description'])
            ->orderByRaw('send_by_at is null')
            ->orderBy('send_by_at')
            ->orderBy('expires_at');
    }

    /** Lo que entró o salió del taller en el período. */
    private function receptionsQuery(?int $orgId, Carbon $desde, Carbon $hasta): Builder
    {
        return $this->forOrg(Reception::query(), $orgId)
            ->where(fn (Builder $q) => $q
                ->whereBetween('received_at', [$desde->toDateString(), $hasta->toDateString()])
                ->orWhereBetween('dispatched_at', [$desde, $hasta]))
            ->with(['asset:id,organization_id,catalog_item_id,description,workshop_description,serial_number',
                'asset.organization:id,name', 'asset.catalogItem:id,description'])
            ->orderByDesc('received_at');
    }

    /** FMR emitidos: finales, por fecha de inspección dentro del período. */
    private function reportsQuery(?int $orgId, Carbon $desde, Carbon $hasta): Builder
    {
        return $this->forOrg(InspectionReport::query(), $orgId)
            ->final()
            ->whereBetween('inspected_at', [$desde->toDateString(), $hasta->toDateString()])
            ->with(['assetSet:id,name', 'organization:id,name'])
            ->withCount('assets')
            ->orderByDesc('inspected_at');
    }

    private function missed(Asset $asset): bool
    {
        return $asset->send_by_at !== null && $asset->send_by_at->lt(today());
    }
}
