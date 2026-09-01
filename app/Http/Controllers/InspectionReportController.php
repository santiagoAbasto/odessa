<?php

namespace App\Http\Controllers;

use App\Actions\FinalizeInspectionReport;
use App\Enums\ReportStatus;
use App\Models\Document;
use App\Models\InspectionReport;
use App\Support\Permissions;
use App\Support\TableQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** FMR — listado, detalle y cierre. Reemplaza `/inspeccion-reportes`. */
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

        $filters = TableQuery::filters($request, ['search', 'status', 'date_from', 'date_to', 'equipment']);

        $query = InspectionReport::query()
            ->with(['assetSet:id,name', 'organization:id,name'])
            ->withCount(['assets', 'documents'])
            ->when($filters['search'] ?? null, fn (Builder $q, string $v) => $q
                ->where('number', 'like', TableQuery::like($v)))
            ->when($filters['status'] ?? null, fn (Builder $q, string $v) => $q->where('status', $v))
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

    public function finalize(InspectionReport $report, FinalizeInspectionReport $finalize): RedirectResponse
    {
        $this->authorize('finalize', $report);

        $finalize($report);

        return back()->with('success', "FMR {$report->number} finalizado. Se actualizaron los estados de las piezas.");
    }
}
