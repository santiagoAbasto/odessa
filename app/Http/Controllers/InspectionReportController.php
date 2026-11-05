<?php

namespace App\Http\Controllers;

use App\Actions\FinalizeInspectionReport;
use App\Actions\RecalculateAssetStatus;
use App\Enums\InspectionResult;
use App\Enums\ReportStatus;
use App\Models\ActivityLog;
use App\Models\Asset;
use App\Models\Document;
use App\Models\InspectionReport;
use App\Models\Organization;
use App\Models\Service;
use App\Support\ActivityLogger;
use App\Support\AuditActions;
use App\Support\Permissions;
use App\Support\TableQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
            'search', 'status', 'date_from', 'date_to', 'equipment', 'organization', 'serial', 'client_reference',
        ]);

        // Informes que incluyen un equipo cuyo dato (serie, referencia…) coincide.
        $deEquipo = fn (Builder $q, string $columna, string $v) => $q
            ->whereHas('assets', fn (Builder $a) => $a->where("assets.{$columna}", 'like', TableQuery::like($v)));

        $query = InspectionReport::query()
            ->with([
                'assetSet:id,name',
                'organization:id,name',
                // Todos los equipos del informe: la lista muestra sus series y
                // referencias, no sólo cuántos son.
                'assets' => fn ($a) => $a->select('assets.id', 'assets.serial_number', 'assets.client_reference', 'assets.code'),
            ])
            ->withCount(['assets', 'documents'])
            // El buscador sirve para ubicar un informe por lo que se inspeccionó:
            // número de informe, o serie, referencia del cliente o código de
            // cualquiera de sus equipos.
            ->when($filters['search'] ?? null, fn (Builder $q, string $v) => $q
                ->where(fn (Builder $i) => $i
                    ->where('number', 'like', TableQuery::like($v))
                    ->orWhereHas('assets', fn (Builder $a) => $a
                        ->where('assets.serial_number', 'like', TableQuery::like($v))
                        ->orWhere('assets.client_reference', 'like', TableQuery::like($v))
                        ->orWhere('assets.code', 'like', TableQuery::like($v)))))
            ->when($filters['serial'] ?? null, fn (Builder $q, string $v) => $deEquipo($q, 'serial_number', $v))
            ->when($filters['client_reference'] ?? null, fn (Builder $q, string $v) => $deEquipo($q, 'client_reference', $v))
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
                // Todas las series del informe (el código si el equipo no tiene
                // serie) y las referencias del cliente, sin repetir.
                'serials' => $r->assets->map(fn (Asset $a) => $a->serial_number ?: $a->code)->filter()->unique()->values()->all(),
                'clientReferences' => $r->assets->pluck('client_reference')->filter()->unique()->values()->all(),
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
            'serviceOptions' => $this->serviceOptions(),
            'can' => ['create' => $request->user()->can('create', InspectionReport::class)],
        ]);
    }

    /** @return list<array{value: string, label: string}> */
    private function serviceOptions(): array
    {
        return Service::query()
            ->orderBy('name')
            ->get(['id', 'code', 'name'])
            ->map(fn (Service $s) => ['value' => (string) $s->id, 'label' => $s->label()])
            ->all();
    }

    /**
     * Cambios del informe, del más nuevo al más viejo. Sólo para quien puede
     * ver la auditoría: es el «para verlo» que pidió el negocio.
     *
     * @return list<array<string, mixed>>
     */
    private function history(InspectionReport $report): array
    {
        return ActivityLog::query()
            ->where('subject_type', InspectionReport::class)
            ->where('subject_id', $report->getKey())
            ->with('user:id,name')
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->map(fn (ActivityLog $log) => [
                'id' => $log->id,
                'label' => AuditActions::label($log->action),
                'tone' => AuditActions::tone($log->action),
                'description' => $log->description,
                'user' => $log->user?->name,
                'at' => $log->created_at?->toIso8601String(),
                'changes' => $log->changes,
            ])
            ->all();
    }

    /**
     * Invariante, no permiso: un informe anulado queda firme.
     *
     * Va acá y no sólo en la Policy porque el superadministrador atraviesa las
     * Policies con Gate::before: si dependiera de ellas, la cuenta que más
     * puede hacer sería la única capaz de saltarse la regla.
     */
    private function assertNotCancelled(InspectionReport $report): void
    {
        abort_if($report->status === ReportStatus::Cancelled, 403, 'Un informe anulado no se puede modificar.');
    }

    /** Recalcula las piezas de un informe: lo que cambió en él puede cambiarles el estado. */
    private function recalculateAssets(InspectionReport $report, RecalculateAssetStatus $recalculate): void
    {
        foreach ($report->assets()->withoutGlobalScope('organization')->get() as $asset) {
            $recalculate($asset);
        }
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
                // Los valores crudos, para precargar el formulario de edición.
                'serviceId' => $report->service_id === null ? '' : (string) $report->service_id,
                'equipmentLabel' => $report->equipment_label,
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
            'serviceOptions' => auth()->user()->can('update', $report) ? $this->serviceOptions() : [],
            // Null y no vacío: «sin permiso» es distinto de «sin cambios todavía».
            'history' => auth()->user()->can(Permissions::AUDIT_VIEW) ? $this->history($report) : null,
            'can' => [
                // El estado manda también para el superadministrador (Gate::before).
                'finalize' => auth()->user()->can('finalize', $report) && $report->status->canTransitionTo(ReportStatus::Final),
                'update' => auth()->user()->can('update', $report) && $report->status !== ReportStatus::Cancelled,
                'cancel' => auth()->user()->can('cancel', $report),
                'delete' => auth()->user()->can('delete', $report),
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
            // 30 = el largo de la columna: con más, la base rechaza el alta con un error 500.
            'number' => ['required', 'string', 'max:30'],
            'inspected_at' => ['required', 'date', 'before_or_equal:today'],
            'service_id' => ['nullable', 'integer', 'exists:services,id'],
            'equipment_label' => ['nullable', 'string', 'max:180'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], [
            'number.required' => 'Indique el número de informe.',
            'number.max' => 'El número de informe admite hasta 30 caracteres.',
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
            return back()->withErrors(['number' => 'Esa empresa ya tiene un informe con ese número.']);
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
     * Corrige los datos del informe: número, fecha, operación, equipo, notas.
     *
     * También vale para uno ya final (sólo el taller: ver la Policy). En ese
     * caso la fecha y la operación entran en el cálculo del estado de las
     * piezas, así que se recalculan. Los vencimientos ya guardados NO se mueven
     * solos con la fecha: se corrigen aparte, en el dictamen de cada pieza.
     */
    public function update(Request $request, InspectionReport $report, RecalculateAssetStatus $recalculate): RedirectResponse
    {
        $this->authorize('update', $report);
        $this->assertNotCancelled($report);

        $data = $request->validate([
            'number' => ['required', 'string', 'max:30'],
            'inspected_at' => ['required', 'date', 'before_or_equal:today'],
            'service_id' => ['nullable', 'integer', 'exists:services,id'],
            'equipment_label' => ['nullable', 'string', 'max:180'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], [
            'number.required' => 'Indique el número de informe.',
            'number.max' => 'El número de informe admite hasta 30 caracteres.',
            'inspected_at.required' => 'Indique la fecha de inspección.',
            'inspected_at.before_or_equal' => 'La fecha de inspección no puede ser futura.',
        ]);

        // El número identifica al informe dentro de la empresa (ver store).
        $repetido = InspectionReport::withoutGlobalScopes()
            ->where('organization_id', $report->organization_id)
            ->where('number', $data['number'])
            ->whereKeyNot($report->getKey())
            ->exists();

        if ($repetido) {
            return back()->withErrors(['number' => 'Esa empresa ya tiene un informe con ese número.']);
        }

        $campos = fn (array $d) => [
            'number' => $d['number'],
            'inspected_at' => Carbon::parse($d['inspected_at'])->toDateString(),
            'service_id' => ($d['service_id'] ?? null) === null ? null : (int) $d['service_id'],
            'equipment_label' => $d['equipment_label'] ?? null,
            'notes' => $d['notes'] ?? null,
        ];

        $antes = $campos([
            'number' => $report->number,
            'inspected_at' => $report->inspected_at,
            'service_id' => $report->service_id,
            'equipment_label' => $report->equipment_label,
            'notes' => $report->notes,
        ]);
        $despues = $campos($data);

        if ($antes === $despues) {
            return back()->with('success', 'No hubo cambios que guardar.');
        }

        $report->update($despues);

        $cambiaron = array_keys(array_diff_assoc($despues, $antes));

        ActivityLogger::log(
            action: 'report.updated',
            subject: $report,
            description: sprintf(
                'Se modificó el informe %s%s',
                $antes['number'],
                $report->status === ReportStatus::Final ? ' (ya finalizado)' : '',
            ),
            old: Arr::only($antes, $cambiaron),
            new: Arr::only($despues, $cambiaron),
            organizationId: $report->organization_id,
        );

        if ($report->status === ReportStatus::Final) {
            $this->recalculateAssets($report, $recalculate);
        }

        return back()->with('success', 'Informe actualizado.');
    }

    /**
     * Da de baja un informe cargado por error.
     *
     * Baja lógica con motivo obligatorio. Si era final, las piezas que
     * dictaminaba se recalculan: dejan de contar ese dictamen. El número se
     * libera —es único por empresa, aun entre los dados de baja— para poder
     * cargar el correcto; el original queda en la auditoría.
     */
    public function destroy(Request $request, InspectionReport $report, RecalculateAssetStatus $recalculate): RedirectResponse
    {
        $this->authorize('delete', $report);

        $data = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ], [
            'reason.required' => 'Indique el motivo de la eliminación.',
            'reason.min' => 'Cuente brevemente el motivo (al menos 5 caracteres).',
        ]);

        $numero = $report->number;
        $estado = $report->status;
        $piezas = $report->assets()->withoutGlobalScope('organization')->count();

        DB::transaction(function () use ($report, $recalculate, $estado) {
            // La columna admite 30: se deja el número recortado más el id, que no se repite.
            $report->forceFill(['number' => Str::limit($report->number, 18, '').'~'.$report->getKey()])->save();
            $report->delete();

            if ($estado === ReportStatus::Final) {
                $this->recalculateAssets($report, $recalculate);
            }
        });

        ActivityLogger::log(
            action: 'report.deleted',
            subject: $report,
            description: "Se eliminó el informe {$numero}: {$data['reason']}",
            old: [
                'number' => $numero,
                'status' => $estado->value,
                'inspected_at' => $report->inspected_at?->toDateString(),
                'assets' => $piezas,
            ],
            new: ['reason' => $data['reason']],
            organizationId: $report->organization_id,
        );

        return redirect('/informes')->with('success', "Informe {$numero} eliminado. Quedó registrado en la auditoría.");
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
        $this->assertNotCancelled($report);

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
    public function updateLines(
        Request $request,
        InspectionReport $report,
        FinalizeInspectionReport $finalize,
        RecalculateAssetStatus $recalculate,
    ): RedirectResponse {
        $this->authorize('update', $report);
        $this->assertNotCancelled($report);

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

        // Lo que había antes: en un informe final es lo que permite reconstruir qué se corrigió.
        $antes = $report->lines()->whereIn('id', $data['line_ids'])->with('asset')->get()
            ->map(fn ($l) => [
                'equipo' => $l->asset?->displayName(),
                'result' => $l->result->value,
                'expires_at' => $l->expires_at?->toDateString(),
            ])->all();

        $actualizadas = $report->lines()
            ->whereIn('id', $data['line_ids'])
            ->update([
                'result' => $resultado->value,
                'expires_at' => $vence,
                'findings' => $data['findings'] ?? null,
            ]);

        // Un informe final ya fijó vencimientos y estados: lo corregido tiene que
        // quedar completo (un «apto» sin vencimiento certificaría para siempre)
        // y las piezas se recalculan, porque acá ya no hay un «finalizar» después.
        $esFinal = $report->status === ReportStatus::Final;
        if ($esFinal) {
            $finalize->fillMissingExpiries($report);
            $this->recalculateAssets($report, $recalculate);
        }

        ActivityLogger::log(
            action: 'report.lines_resolved',
            subject: $report,
            description: sprintf(
                'Dictamen «%s» sobre %d equipos del informe %s%s',
                $resultado->label(),
                $actualizadas,
                $report->number,
                $esFinal ? ' (ya finalizado)' : '',
            ),
            old: ['lines' => $antes],
            new: ['result' => $resultado->value, 'expires_at' => $vence],
            organizationId: $report->organization_id,
        );

        return back()->with('success', "Se dictaminaron {$actualizadas} equipos.");
    }

    /** Quita un equipo cargado por error. En un informe final, además, la pieza se recalcula. */
    public function removeAsset(
        Request $request,
        InspectionReport $report,
        string $assetId,
        RecalculateAssetStatus $recalculate,
    ): RedirectResponse {
        $this->authorize('update', $report);
        $this->assertNotCancelled($report);

        $asset = Asset::withoutGlobalScopes()
            ->where('organization_id', $report->organization_id)
            ->where('public_id', $assetId)
            ->firstOrFail();

        $report->assets()->detach($asset->getKey());

        if ($report->status === ReportStatus::Final) {
            $recalculate($asset);
        }

        ActivityLogger::log(
            action: 'report.asset_removed',
            subject: $report,
            description: "Se quitó {$asset->displayName()} del informe {$report->number}",
            organizationId: $report->organization_id,
        );

        return back()->with('success', 'Equipo quitado del informe.');
    }

    /**
     * Equipos de la empresa que todavía no están en este informe, buscados por
     * lo que escribe la persona.
     *
     * Antes traía los primeros 300 y filtraba en el navegador: con cientos de
     * equipos, los últimos del orden no se podían agregar a ningún informe. Una
     * tanda de 50 alcanza para tildar de a varios (un informe típico dictamina
     * una tanda) sin cargar la empresa entera.
     */
    public function availableAssets(Request $request, InspectionReport $report): JsonResponse
    {
        $this->authorize('update', $report);

        $term = trim((string) $request->query('q'));

        $assets = Asset::withoutGlobalScopes()
            ->where('assets.organization_id', $report->organization_id)
            ->whereNull('assets.deleted_at')
            ->whereNotIn('assets.id', $report->lines()->pluck('asset_id'))
            ->when($term !== '', fn ($q) => $q->matching($term))
            ->orderBy('assets.description')
            ->limit(50)
            ->get(['assets.id', 'assets.public_id', 'assets.description', 'assets.serial_number', 'assets.code', 'assets.catalog_item_id', 'assets.workshop_description'])
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

        return back()->with('success', "Informe {$report->number} finalizado. Se actualizaron los estados de las piezas.");
    }
}
