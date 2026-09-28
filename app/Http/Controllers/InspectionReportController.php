<?php

namespace App\Http\Controllers;

use App\Actions\FinalizeInspectionReport;
use App\Enums\InspectionResult;
use App\Enums\ReportStatus;
use App\Models\Asset;
use App\Models\Document;
use App\Models\InspectionReport;
use App\Models\Organization;
use App\Models\Service;
use App\Support\ActivityLogger;
use App\Support\Permissions;
use App\Support\TableQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * FMR — alta, carga de equipos, dictamen y cierre.
 *
 * El recorrido que definió el negocio:
 *
 *   1. se crea el informe en borrador;
 *   2. se le suman los equipos inspeccionados, de a varios;
 *   3. se los dictamina en tanda, con su vencimiento;
 *   4. se finaliza, y recién ahí cada pieza toma su estado y su vigencia.
 *
 * Nada de lo que pase antes del paso 4 altera el estado de una pieza: un
 * borrador es una declaración en curso, no un dictamen.
 */
class InspectionReportController extends Controller
{
    private const SORTABLE = [
        'number' => 'inspection_reports.number',
        'inspected_at' => 'inspection_reports.inspected_at',
        'status' => 'inspection_reports.status',
        'equipment' => 'inspection_reports.equipment_label',
    ];

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', InspectionReport::class);

        $filters = TableQuery::filters($request, [
            'search', 'status', 'date_from', 'date_to', 'equipment', 'organization',
        ]);

        $query = InspectionReport::query()
            ->with(['assetSet:id,name', 'organization:id,name'])
            ->withCount(['assets', 'documents'])
            ->when($filters['search'] ?? null, fn (Builder $q, string $v) => $q
                ->where('number', 'like', TableQuery::like($v)))
            ->when($filters['status'] ?? null, fn (Builder $q, string $v) => $q->where('status', $v))
            ->tap(fn (Builder $q) => TableQuery::scopeToOrganization($q, $filters['organization'] ?? null))
            ->when($filters['equipment'] ?? null, fn (Builder $q, string $v) => $q
                ->where(fn (Builder $i) => $i
                    ->where('equipment_label', 'like', TableQuery::like($v))
                    ->orWhereHas('assetSet', fn (Builder $s) => $s->where('name', 'like', TableQuery::like($v)))))
            ->when($filters['date_from'] ?? null, fn (Builder $q, string $v) => $q->whereDate('inspected_at', '>=', $v))
            ->when($filters['date_to'] ?? null, fn (Builder $q, string $v) => $q->whereDate('inspected_at', '<=', $v));

        $reports = TableQuery::sort($query, $request, self::SORTABLE, 'inspected_at')
            ->paginate(TableQuery::perPage($request))
            ->withQueryString()
            ->through(fn (InspectionReport $r) => [
                'id' => $r->public_id,
                'number' => $r->number,
                // De qué cliente es el informe.
                'organization' => $r->organization?->name,
                'equipment' => $r->equipmentName(),
                'inspectedAt' => $r->inspected_at?->toDateString(),
                'assetsCount' => $r->assets_count,
                'filesCount' => $r->documents_count,
                'status' => $r->status->value,
                'statusLabel' => $r->status->label(),
                'statusTone' => $r->status->tone(),
            ]);

        return Inertia::render('reports/index', [
            'reports' => $reports,
            'filters' => $filters,
            'sort' => ['column' => $request->query('sort', 'inspected_at'), 'direction' => $request->query('direction', 'desc')],
            'statusOptions' => ReportStatus::options(),
            'showOrganization' => ! $request->user()->isClientUser(),
            'organizationOptions' => TableQuery::organizationOptions($request),
            'serviceOptions' => Service::query()
                ->orderBy('name')
                ->get(['id', 'code', 'name'])
                ->map(fn (Service $s) => ['value' => (string) $s->id, 'label' => $s->label()])
                ->all(),
            'can' => ['create' => $request->user()->can('create', InspectionReport::class)],
        ]);
    }

    public function show(InspectionReport $report): Response
    {
        $this->authorize('view', $report);

        $report->load([
            'assetSet:id,public_id,name',
            'service:id,code,name',
            'creator:id,name',
            'finalizer:id,name',
            'documents.uploader:id,name',
            'lines.asset.catalogItem:id,description,part_number',
            'lines.service:id,code,name',
        ]);

        return Inertia::render('reports/show', [
            'report' => [
                'id' => $report->public_id,
                'number' => $report->number,
                'equipment' => $report->equipmentName(),
                'setId' => $report->assetSet?->public_id,
                'inspectedAt' => $report->inspected_at?->toDateString(),
                'status' => $report->status->value,
                'statusLabel' => $report->status->label(),
                'statusTone' => $report->status->tone(),
                'service' => $report->service?->label(),
                'notes' => $report->notes,
                'createdBy' => $report->creator?->name,
                'finalizedBy' => $report->finalizer?->name,
                'finalizedAt' => $report->finalized_at?->toIso8601String(),
            ],
            'lines' => $report->lines->map(fn ($line) => [
                'id' => $line->id,
                'asset' => [
                    'id' => $line->asset->public_id,
                    'name' => $line->asset->displayName(),
                    'serialNumber' => $line->asset->serial_number,
                    'code' => $line->asset->code,
                    'clientReference' => $line->asset->client_reference,
                    'partNumber' => $line->asset->catalogItem?->part_number,
                    'kind' => $line->asset->kind,
                ],
                'result' => $line->result->value,
                'resultLabel' => $line->result->label(),
                'resultTone' => $line->result->tone(),
                'service' => $line->service?->label(),
                'expiresAt' => $line->expires_at?->toDateString(),
                'seal' => $line->seal,
                'findings' => $line->findings,
            ])->all(),
            'files' => $report->documents->map(fn ($d) => $d->toPayload())->all(),
            'categories' => collect(Document::CATEGORIES)
                ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
                ->values(),
            'can' => [
                'finalize' => auth()->user()->can('finalize', $report),
                'update' => auth()->user()->can('update', $report),
                'cancel' => auth()->user()->can('cancel', $report),
                'upload' => auth()->user()->can(Permissions::DOCUMENTS_UPLOAD),
                'deleteDocuments' => auth()->user()->can(Permissions::DOCUMENTS_DELETE),
            ],
        ]);
    }

    /**
     * Crea el informe en borrador.
     *
     * Arranca vacío: primero existe el informe, después se le suman los
     * equipos y se los dictamina. Nada de esto altera el estado de una pieza
     * hasta que el informe se finaliza, que es el único momento en que un
     * dictamen pasa a valer.
     */
    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', InspectionReport::class);

        $data = $request->validate([
            'organization_public_id' => ['required', 'uuid'],
            'number' => ['required', 'string', 'max:60'],
            'inspected_at' => ['required', 'date', 'before_or_equal:today'],
            'service_id' => ['nullable', 'integer', 'exists:services,id'],
            'equipment_label' => ['nullable', 'string', 'max:180'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], [
            'number.required' => 'Indique el número de FMR.',
            'inspected_at.required' => 'Indique la fecha de inspección.',
            'inspected_at.before_or_equal' => 'La fecha de inspección no puede ser futura.',
        ]);

        $organization = Organization::query()
            ->where('public_id', $data['organization_public_id'])
            ->first();

        if ($organization === null) {
            return back()->withErrors(['organization_public_id' => 'La empresa elegida no existe.']);
        }

        /*
         * El número identifica al informe dentro de la empresa. Repetirlo
         * haría ambiguo cualquier reclamo posterior: "el FMR 1234" tiene que
         * señalar a uno solo.
         */
        $repetido = InspectionReport::withoutGlobalScopes()
            ->where('organization_id', $organization->getKey())
            ->where('number', $data['number'])
            ->exists();

        if ($repetido) {
            return back()->withErrors(['number' => 'Esa empresa ya tiene un FMR con ese número.']);
        }

        $report = InspectionReport::create([
            'organization_id' => $organization->getKey(),
            'number' => $data['number'],
            'inspected_at' => $data['inspected_at'],
            'service_id' => $data['service_id'] ?? null,
            'equipment_label' => $data['equipment_label'] ?? null,
            'notes' => $data['notes'] ?? null,
            'status' => ReportStatus::Draft,
            'created_by' => $request->user()->getKey(),
        ]);

        ActivityLogger::log(
            action: 'report.created',
            subject: $report,
            description: "Alta del informe {$report->number}",
            organizationId: $organization->getKey(),
        );

        return redirect("/informes/{$report->public_id}")
            ->with('success', 'Informe creado. Agregue los equipos inspeccionados.');
    }

    /**
     * Suma equipos al informe, de a varios.
     *
     * Se cargan sin dictamen: entran como «sin procesar» y después se
     * resuelven en tanda. Es el orden real del taller —primero se arma la
     * lista de lo que entró, después se inspecciona— y evita tener que crear
     * una línea por vez.
     */
    public function addAssets(Request $request, InspectionReport $report): RedirectResponse
    {
        $this->authorize('update', $report);

        $data = $request->validate([
            'asset_public_ids' => ['required', 'array', 'min:1'],
            'asset_public_ids.*' => ['uuid'],
        ], [
            'asset_public_ids.required' => 'Elija al menos un equipo.',
        ]);

        // Sólo equipos de la misma empresa que el informe: un FMR no puede
        // mezclar piezas de dos clientes.
        $assets = Asset::withoutGlobalScopes()
            ->where('organization_id', $report->organization_id)
            ->whereIn('public_id', $data['asset_public_ids'])
            ->get();

        if ($assets->isEmpty()) {
            return back()->withErrors(['asset_public_ids' => 'Ninguno de los equipos elegidos pertenece a esta empresa.']);
        }

        // syncWithoutDetaching y no attach: reintentar no puede duplicar
        // líneas ni pisar el dictamen de las que ya estaban.
        $report->assets()->syncWithoutDetaching(
            $assets->mapWithKeys(fn (Asset $a) => [
                $a->getKey() => ['result' => InspectionResult::SinProcesar->value],
            ])->all(),
        );

        ActivityLogger::log(
            action: 'report.assets_added',
            subject: $report,
            description: sprintf('Se agregaron %d equipos al informe %s', $assets->count(), $report->number),
            new: ['assets' => $assets->pluck('serial_number')->all()],
            organizationId: $report->organization_id,
        );

        return back()->with('success', "Se agregaron {$assets->count()} equipos al informe.");
    }

    /**
     * Dictamina una o varias líneas a la vez.
     *
     * Lo pidió el negocio así: se aprueban varios equipos de una, porque un
     * FMR típico dictamina una tanda entera con el mismo resultado.
     *
     * El vencimiento puede dejarse vacío: al finalizar se calcula desde la
     * vigencia de la operación. Cargarlo acá sirve para el caso en que el
     * certificado traiga una fecha propia.
     */
    public function updateLines(Request $request, InspectionReport $report): RedirectResponse
    {
        $this->authorize('update', $report);

        $data = $request->validate([
            'line_ids' => ['required', 'array', 'min:1'],
            'line_ids.*' => ['integer'],
            'result' => ['required', Rule::enum(InspectionResult::class)],
            'expires_at' => ['nullable', 'date', 'after:today'],
            'findings' => ['nullable', 'string', 'max:2000'],
        ], [
            'line_ids.required' => 'Elija al menos una línea.',
            'result.required' => 'Indique el resultado.',
            'expires_at.after' => 'Un vencimiento ya pasado no certifica nada.',
        ]);

        $resultado = InspectionResult::from($data['result']);

        /*
         * Un resultado que no certifica no puede llevar vencimiento: dejarlo
         * haría que la pieza mostrara una vigencia que su dictamen no
         * respalda.
         */
        $vence = $resultado === InspectionResult::Apto ? ($data['expires_at'] ?? null) : null;

        $actualizadas = $report->lines()
            ->whereIn('id', $data['line_ids'])
            ->update([
                'result' => $resultado->value,
                'expires_at' => $vence,
                'findings' => $data['findings'] ?? null,
            ]);

        ActivityLogger::log(
            action: 'report.lines_resolved',
            subject: $report,
            description: sprintf(
                'Dictamen «%s» sobre %d equipos del informe %s',
                $resultado->label(),
                $actualizadas,
                $report->number,
            ),
            new: ['result' => $resultado->value, 'expires_at' => $vence],
            organizationId: $report->organization_id,
        );

        return back()->with('success', "Se dictaminaron {$actualizadas} equipos.");
    }

    /** Quita un equipo cargado por error. Sólo mientras el informe es borrador. */
    public function removeAsset(Request $request, InspectionReport $report, string $assetId): RedirectResponse
    {
        $this->authorize('update', $report);

        $asset = Asset::withoutGlobalScopes()
            ->where('organization_id', $report->organization_id)
            ->where('public_id', $assetId)
            ->firstOrFail();

        $report->assets()->detach($asset->getKey());

        ActivityLogger::log(
            action: 'report.asset_removed',
            subject: $report,
            description: "Se quitó {$asset->displayName()} del informe {$report->number}",
            organizationId: $report->organization_id,
        );

        return back()->with('success', 'Equipo quitado del informe.');
    }

    /** Equipos de la empresa que todavía no están en este informe. */
    public function availableAssets(InspectionReport $report): JsonResponse
    {
        $this->authorize('update', $report);

        $assets = Asset::withoutGlobalScopes()
            ->where('organization_id', $report->organization_id)
            ->whereNotIn('id', $report->lines()->pluck('asset_id'))
            ->orderBy('description')
            ->limit(300)
            ->get(['public_id', 'description', 'serial_number', 'code'])
            ->map(fn (Asset $a) => [
                'id' => $a->public_id,
                'name' => $a->displayName(),
                'serialNumber' => $a->serial_number,
                'code' => $a->code,
            ]);

        return response()->json(['assets' => $assets]);
    }

    public function finalize(InspectionReport $report, FinalizeInspectionReport $finalize): RedirectResponse
    {
        $this->authorize('finalize', $report);

        $finalize($report);

        return back()->with('success', "FMR {$report->number} finalizado. Se actualizaron los estados de las piezas.");
    }
}
