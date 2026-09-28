<?php

namespace App\Http\Controllers\Workshop;

use App\Actions\ChangeReceptionStage;
use App\Actions\RegisterDirectReception;
use App\Actions\StoreDocument;
use App\Http\Controllers\Controller;
use App\Http\Requests\RecordRepairDecisionRequest;
use App\Models\Asset;
use App\Models\Organization;
use App\Models\Reception;
use App\Support\TableQuery;
use Illuminate\Validation\Rule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Recepción de equipos (área Taller).
 *
 * El sistema auditado separaba "En taller" y "Despachados" en dos vistas con
 * querystring. Aquí es una sola tabla con un filtro de etapa explícito, y las
 * acciones de liberar/despachar viven en la fila en lugar de en otra pantalla.
 */
class ReceptionController extends Controller
{
    private const SORTABLE = [
        'received_at' => 'receptions.received_at',
        'promised_at' => 'receptions.promised_at',
        'urgent' => 'receptions.is_urgent',
        'released_at' => 'receptions.released_at',
        'dispatched_at' => 'receptions.dispatched_at',
    ];

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Reception::class);

        $filters = TableQuery::filters($request, ['search', 'stage', 'urgent', 'overdue', 'report_number', 'reception']);

        $query = Reception::query()
            ->with([
                'asset:id,public_id,description,workshop_description,serial_number,code,expires_at,status,catalog_item_id,organization_id',
                'asset.organization:id,name',
                'asset.catalogItem:id,description',
                'inspectionReport:id,public_id,number',
                // Lo que pidió el cliente para este equipo al mandarlo.
                'shipmentItem:id,reception_id,special_operation',
                'shipmentItem.operations:id,code,name',
            ])
            ->select('receptions.*');

        match ($filters['stage'] ?? 'in_workshop') {
            'dispatched' => $query->dispatched(),
            'ready' => $query->readyToDispatch(),
            'rejected' => $query->rejected(),
            'awaiting_client' => $query->awaitingClient(),
            'repair' => $query->repairQueue(),
            'pending' => $query->pending(),
            'in_progress' => $query->inProgress(),
            'all' => $query,
            default => $query->inWorkshop(),
        };

        $query
            // Enlace directo de los avisos por correo. El scope de organización
            // ya hace que una recepción ajena simplemente no aparezca.
            ->when($filters['reception'] ?? null, fn (Builder $q, string $v) => $q->where('receptions.public_id', $v))
            ->when(($filters['urgent'] ?? null) === '1', fn (Builder $q) => $q->where('is_urgent', true))
            ->when(($filters['overdue'] ?? null) === '1', fn (Builder $q) => $q->overdue())
            ->when($filters['report_number'] ?? null, fn (Builder $q, string $v) => $q
                ->whereHas('inspectionReport', fn (Builder $r) => $r->where('number', 'like', TableQuery::like($v))))
            ->when($filters['search'] ?? null, fn (Builder $q, string $v) => $q
                ->whereHas('asset', fn (Builder $a) => $a
                    ->where('description', 'like', TableQuery::like($v))
                    ->orWhere('workshop_description', 'like', TableQuery::like($v))
                    ->orWhere('serial_number', 'like', TableQuery::like($v))
                    ->orWhere('code', 'like', TableQuery::like($v))));

        $user = $request->user();

        $page = TableQuery::sort($query, $request, self::SORTABLE, 'received_at')
            ->paginate(TableQuery::perPage($request))
            ->withQueryString();

        // Nombre de cada operación para cada empresa de la página, en una sola
        // consulta: la misma operación puede llamarse distinto en cada cliente.
        $nombres = DB::table('organization_service')
            ->whereIn('organization_id', $page->getCollection()->pluck('organization_id')->unique())
            ->whereNotNull('name')
            ->get(['organization_id', 'service_id', 'name'])
            ->groupBy('organization_id')
            ->map(fn ($filas) => $filas->pluck('name', 'service_id')->all());

        $receptions = $page->through(fn (Reception $r) => [
                'id' => $r->public_id,
                'isUrgent' => $r->is_urgent,
                'receivedAt' => $r->received_at?->toDateString(),
                'promisedAt' => $r->promised_at?->toDateString(),
                'isOverdue' => $r->isOverdue(),
                'stage' => $r->stage(),
                'stageLabel' => $r->stageLabel(),
                'stageTone' => $r->stageTone(),
                'startedAt' => $r->started_at?->toIso8601String(),
                'releasedAt' => $r->released_at?->toIso8601String(),
                'outcome' => $r->outcome,
                'outcomeNotes' => $r->outcome_notes,
                'repairRequestedAt' => $r->repair_requested_at?->toIso8601String(),
                'repairRequestNotes' => $r->repair_request_notes,
                'repairDecision' => $r->repair_decision,
                'repairDecidedAt' => $r->repair_decided_at?->toIso8601String(),
                'repairDecisionChannel' => $r->decisionChannelLabel(),
                'repairDecisionNotes' => $r->repair_decision_notes,
                'repairStartedAt' => $r->repair_started_at?->toIso8601String(),
                'repairedAt' => $r->repaired_at?->toIso8601String(),
                'repairFailedAt' => $r->repair_failed_at?->toIso8601String(),
                'repairFailureNotes' => $r->repair_failure_notes,
                'unrepairableAt' => $r->unrepairable_at?->toIso8601String(),
                'unrepairableNotes' => $r->unrepairable_notes,
                'dispatchedAt' => $r->dispatched_at?->toIso8601String(),
                'reportNumber' => $r->inspectionReport?->number,
                'reportId' => $r->inspectionReport?->public_id,
                'requested' => $r->shipmentItem?->requestedNames(
                    $nombres->get($r->organization_id, []),
                    withCode: ! $user->isClientUser(),
                ) ?? [],
                'specialOperation' => $r->shipmentItem?->special_operation,
                'asset' => [
                    'id' => $r->asset->public_id,
                    'name' => $r->asset->workshop_description ?: $r->asset->displayName(),
                    // De qué cliente es la pieza. En el taller conviven equipos
                    // de todas las empresas: sin esto no hay forma de saberlo.
                    'organization' => $r->asset->organization?->name,
                    'serialNumber' => $r->asset->serial_number,
                    'code' => $r->asset->code,
                    'expiresAt' => $r->asset->expires_at?->toDateString(),
                    'status' => $r->asset->status->value,
                    'statusLabel' => $r->asset->status->label(),
                    'statusTone' => $r->asset->status->tone(),
                ],
                /*
                 * Cada acción se ofrece sólo en la etapa donde tiene sentido.
                 * Así el taller no elige entre botones que van a fallar.
                 */
                'can' => (function () use ($r, $user) {
                    $stage = $r->stage();
                    $release = $user->can('release', $r);

                    return [
                        'start' => ! $r->hasStarted() && ! $r->isResolved() && $release,
                        'resolve' => $r->hasStarted() && ! $r->isResolved() && $release,
                        // Desde Rechazadas, dos salidas: consultar al cliente
                        // (una sola vez) o marcarla sin solución.
                        'requestRepair' => $stage === 'rejected' && $release,
                        'markUnrepairable' => in_array($stage, ['rejected', 'repair_failed'], true) && $release,
                        // Contestar la consulta. Es lo único que un usuario
                        // cliente puede hacer sobre una recepción.
                        'decideRepair' => $user->can('authorizeRepair', $r),
                        'sendToRepair' => $stage === 'repair_authorized' && $release,
                        // La reparación en curso se dictamina como cualquier trabajo.
                        'markRepaired' => $stage === 'in_repair' && $release,
                        'failRepair' => $stage === 'in_repair' && $release,
                        'dispatch' => $r->isDispatchable() && $user->can('dispatchReception', $r),
                    ];
                })(),
            ]);

        return Inertia::render('workshop/receptions', [
            'receptions' => $receptions,
            'filters' => $filters + ['stage' => $filters['stage'] ?? 'in_workshop'],
            'sort' => ['column' => $request->query('sort', 'received_at'), 'direction' => $request->query('direction', 'desc')],
            // Un cliente ve sólo lo suyo; para el taller la empresa es un dato
            // identificatorio más de la pieza.
            'showOrganization' => ! $user->isClientUser(),
            // Cambia el formulario de autorización de reparación: el cliente
            // contesta y listo; Odessa debe declarar la vía y adjuntar respaldo.
            'isClient' => $user->isClientUser(),
            'can' => [
                // Ingreso directo: sólo el personal del taller lo necesita.
                'create' => $user->can('create', Reception::class),
            ],
            'organizationOptions' => $user->isClientUser() ? [] : Organization::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['public_id', 'name'])
                ->map(fn (Organization $o) => ['value' => $o->public_id, 'label' => $o->name])
                ->all(),
            'counts' => Inertia::defer(fn () => $this->stageCounts()),
        ]);
    }

    /**
     * Ingreso directo, sin envío previo del cliente.
     *
     * Es la puerta de atrás que el negocio pidió: llegan equipos sin nada
     * cargado y el taller tiene que poder registrarlos igual. Se elige la
     * empresa —el equipo es de alguien— y después una pieza ya registrada o
     * una nueva que se da de alta en el momento.
     */
    public function store(Request $request, RegisterDirectReception $register): RedirectResponse
    {
        $this->authorize('create', Reception::class);

        $data = $request->validate([
            'organization_public_id' => ['required', 'uuid'],
            'asset_public_id' => ['nullable', 'uuid'],
            'description' => ['nullable', 'string', 'min:3', 'max:180'],
            'serial_number' => ['nullable', 'string', 'max:120'],
            'client_reference' => ['nullable', 'string', 'max:120'],
            'kind' => ['nullable', Rule::in([Asset::KIND_PART, Asset::KIND_GENERIC])],
            'received_at' => ['required', 'date', 'before_or_equal:today'],
            'promised_at' => ['nullable', 'date', 'after_or_equal:received_at'],
            'is_urgent' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:500'],
        ], [
            'organization_public_id.required' => 'Indique de qué cliente es el equipo.',
            'received_at.required' => 'Indique la fecha de ingreso.',
            'received_at.before_or_equal' => 'La fecha de ingreso no puede ser futura.',
            'promised_at.after_or_equal' => 'La fecha comprometida no puede ser anterior al ingreso.',
        ]);

        $organization = Organization::where('public_id', $data['organization_public_id'])
            ->where('is_active', true)
            ->first();

        if ($organization === null) {
            return back()->withErrors(['organization_public_id' => 'La empresa elegida no existe o está inactiva.']);
        }

        $reception = $register($organization, $data);

        return back()->with(
            'success',
            "Equipo ingresado. Queda pendiente en el taller ({$reception->asset->displayName()})."
        );
    }

    /** Piezas de una empresa, para el desplegable del ingreso directo. */
    public function assetsFor(Request $request, Organization $organization): JsonResponse
    {
        $this->authorize('create', Reception::class);

        return response()->json(
            Asset::query()
                ->forOrganization($organization->getKey())
                ->orderBy('description')
                ->limit(500)
                ->get(['public_id', 'description', 'serial_number', 'code'])
                ->map(fn (Asset $a) => [
                    'value' => $a->public_id,
                    'label' => trim($a->description.' · '.($a->serial_number ?? $a->code ?? 'sin serie')),
                ])
        );
    }

    public function start(Reception $reception, ChangeReceptionStage $stages): RedirectResponse
    {
        // Empezar el trabajo es parte de liberarlo: mismo permiso, un hito antes.
        $this->authorize('release', $reception);

        $stages->start($reception);

        return back()->with('success', 'Trabajo iniciado.');
    }

    /** Dictamen del trabajo: aprobar o rechazar. */
    public function resolve(Request $request, Reception $reception, ChangeReceptionStage $stages): RedirectResponse
    {
        $this->authorize('release', $reception);

        $data = $request->validate([
            'outcome' => ['required', Rule::in([Reception::OUTCOME_APPROVED, Reception::OUTCOME_REJECTED])],
            'outcome_notes' => ['nullable', 'string', 'max:500'],
        ], [
            'outcome.required' => 'Indique si aprueba o rechaza el trabajo.',
        ]);

        $stages->resolve($reception, $data['outcome'], $data['outcome_notes'] ?? null);

        return back()->with('success', $data['outcome'] === Reception::OUTCOME_APPROVED
            ? 'Trabajo aprobado. La pieza queda lista para despachar.'
            : 'Trabajo rechazado. Decida si pasa a reparación.');
    }

    /** Le pide al cliente que decida si autoriza la reparación. */
    public function requestRepair(Request $request, Reception $reception, ChangeReceptionStage $stages): RedirectResponse
    {
        $this->authorize('release', $reception);

        $validated = $request->validate([
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $stages->requestRepairAuthorization($reception, $validated['notes'] ?? null);

        return back()->with('success', 'Se le pidió al cliente que autorice la reparación.');
    }

    /**
     * Registra la respuesta del cliente.
     *
     * La usan tanto el cliente desde su panel como el taller transcribiendo lo
     * que recibió por fuera. En el segundo caso conviene adjuntar la evidencia
     * —captura del correo o del mensaje—, porque es lo que respalda el cobro
     * de la reparación si después se discute.
     */
    public function recordRepairDecision(
        RecordRepairDecisionRequest $request,
        Reception $reception,
        ChangeReceptionStage $stages,
        StoreDocument $storeDocument,
    ): RedirectResponse {
        $this->authorize('authorizeRepair', $reception);

        $stages->recordRepairDecision(
            $reception,
            $request->string('decision')->value(),
            $request->string('channel')->value(),
            $request->input('notes'),
        );

        if ($request->hasFile('evidence')) {
            $storeDocument(
                $reception,
                $request->file('evidence'),
                'repair_authorization',
                null,
                'Respuesta del cliente sobre la reparación',
            );
        }

        return back()->with(
            'success',
            $request->string('decision')->value() === Reception::REPAIR_APPROVED
                ? 'Reparación autorizada. La pieza queda lista para repararse.'
                : 'Se registró que no se autoriza. La pieza se devuelve sin reparar.',
        );
    }

    public function sendToRepair(Reception $reception, ChangeReceptionStage $stages): RedirectResponse
    {
        $this->authorize('release', $reception);

        $stages->sendToRepair($reception);

        return back()->with('success', 'Reparación iniciada. La pieza pasa a En proceso.');
    }

    public function markRepaired(Reception $reception, ChangeReceptionStage $stages): RedirectResponse
    {
        $this->authorize('release', $reception);

        $stages->markRepaired($reception);

        return back()->with('success', 'Reparación aprobada. La pieza queda lista para despachar.');
    }

    public function failRepair(Request $request, Reception $reception, ChangeReceptionStage $stages): RedirectResponse
    {
        $this->authorize('release', $reception);

        $data = $request->validate(['repair_failure_notes' => ['nullable', 'string', 'max:2000']]);

        $stages->failRepair($reception, $data['repair_failure_notes'] ?? null);

        return back()->with('success', 'Reparación rechazada. La pieza vuelve a Rechazadas.');
    }

    public function markUnrepairable(Request $request, Reception $reception, ChangeReceptionStage $stages): RedirectResponse
    {
        $this->authorize('release', $reception);

        $data = $request->validate(['unrepairable_notes' => ['nullable', 'string', 'max:2000']]);

        $stages->markUnrepairable($reception, $data['unrepairable_notes'] ?? null);

        return back()->with('success', 'Pieza marcada sin solución. Queda lista para devolver, no apta.');
    }

    public function dispatchReception(Request $request, Reception $reception, ChangeReceptionStage $stages): RedirectResponse
    {
        $this->authorize('dispatchReception', $reception);

        $validated = $request->validate([
            'dispatch_note' => ['nullable', 'string', 'max:120'],
        ]);

        $stages->dispatch($reception, $validated['dispatch_note'] ?? null);

        return back()->with('success', 'Pieza despachada.');
    }

    private function stageCounts(): array
    {
        return [
            'pending' => Reception::query()->pending()->count(),
            'in_progress' => Reception::query()->inProgress()->count(),
            'rejected' => Reception::query()->rejected()->count(),
            'awaiting_client' => Reception::query()->awaitingClient()->count(),
            'repair' => Reception::query()->repairQueue()->count(),
            'ready' => Reception::query()->readyToDispatch()->count(),
            'in_workshop' => Reception::query()->inWorkshop()->count(),
            'dispatched' => Reception::query()->dispatched()->count(),
            'overdue' => Reception::query()->overdue()->count(),
            'urgent' => Reception::query()->inWorkshop()->where('is_urgent', true)->count(),
        ];
    }
}
