<?php

namespace App\Http\Controllers;

use App\Actions\AuthorizeShipment;
use App\Models\Asset;
use App\Models\Shipment;
use App\Support\ActivityLogger;
use App\Support\Permissions;
use App\Support\TableQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Envíos pre-cargados por el cliente.
 *
 * El cliente operador declara qué equipos manda al taller. El taller lo
 * autoriza y, sólo entonces, se crean las recepciones. Es la misma pantalla
 * para ambos roles: cambia lo que cada uno puede hacer en ella.
 */
class ShipmentController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Shipment::class);

        $user = $request->user();
        $filters = TableQuery::filters($request, ['status', 'search']);

        $shipments = Shipment::query()
            ->with(['creator:id,name', 'reviewer:id,name', 'organization:id,name'])
            ->withCount('items')
            ->when($filters['status'] ?? null, fn (Builder $q, string $v) => $q->where('status', $v))
            ->when($filters['search'] ?? null, fn (Builder $q, string $v) => $q
                ->where(fn (Builder $i) => $i
                    ->where('reference', 'like', TableQuery::like($v))
                    ->orWhere('client_reference', 'like', TableQuery::like($v))))
            /*
             * Lo pendiente de autorizar primero: es la cola de trabajo del taller.
             * Se usa CASE y no FIELD() porque FIELD() sólo existe en MySQL y
             * rompería en cualquier otro motor.
             */
            ->orderByRaw(
                "CASE status
                    WHEN 'submitted' THEN 0
                    WHEN 'draft' THEN 1
                    WHEN 'authorized' THEN 2
                    ELSE 3
                 END"
            )
            ->orderByDesc('created_at')
            ->paginate(TableQuery::perPage($request))
            ->withQueryString()
            ->through(fn (Shipment $s) => [
                'id' => $s->public_id,
                'reference' => $s->reference,
                'clientReference' => $s->client_reference,
                'status' => $s->status,
                'statusLabel' => $s->statusLabel(),
                'statusTone' => $s->statusTone(),
                'itemsCount' => $s->items_count,
                'expectedAt' => $s->expected_at?->toDateString(),
                'organization' => $s->organization?->name,
                'createdBy' => $s->creator?->name,
                'createdAt' => $s->created_at?->toIso8601String(),
                'reviewedBy' => $s->reviewer?->name,
                'reviewedAt' => $s->reviewed_at?->toIso8601String(),
            ]);

        return Inertia::render('shipments/index', [
            'shipments' => $shipments,
            'filters' => $filters,
            'statusOptions' => collect(Shipment::STATUS_LABELS)
                ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
                ->values(),
            'can' => [
                'create' => $user->can('create', Shipment::class),
                'authorize' => $user->can(Permissions::SHIPMENTS_AUTHORIZE),
            ],
            // Lo que el sistema ya sabe y el formulario rellena solo.
            'prefill' => [
                'organization' => $user->organization?->name,
                'nextReference' => $user->isClientUser()
                    ? $this->previewReference($user->organization_id)
                    : null,
                'contactName' => $user->name,
                'contactPhone' => $user->phone,
            ],
            'pendingCount' => Inertia::defer(
                fn () => Shipment::query()->where('status', Shipment::STATUS_SUBMITTED)->count()
            ),
        ]);
    }

    public function show(Request $request, Shipment $shipment): Response
    {
        $this->authorize('view', $shipment);

        $shipment->load([
            'items.asset:id,public_id,description,serial_number,code',
            'items.reception:id,public_id',
            'creator:id,name',
            'reviewer:id,name',
            'organization:id,name',
        ]);

        $user = $request->user();

        return Inertia::render('shipments/show', [
            'shipment' => [
                'id' => $shipment->public_id,
                'reference' => $shipment->reference,
                'clientReference' => $shipment->client_reference,
                'status' => $shipment->status,
                'statusLabel' => $shipment->statusLabel(),
                'statusTone' => $shipment->statusTone(),
                'expectedAt' => $shipment->expected_at?->toDateString(),
                'contactName' => $shipment->contact_name,
                'contactPhone' => $shipment->contact_phone,
                'carrier' => $shipment->carrier,
                'origin' => $shipment->origin,
                'notes' => $shipment->notes,
                'organization' => $shipment->organization?->name,
                'createdBy' => $shipment->creator?->name,
                'createdAt' => $shipment->created_at?->toIso8601String(),
                'submittedAt' => $shipment->submitted_at?->toIso8601String(),
                'reviewedBy' => $shipment->reviewer?->name,
                'reviewedAt' => $shipment->reviewed_at?->toIso8601String(),
                'reviewNotes' => $shipment->review_notes,
            ],
            'items' => $shipment->items->map(fn ($item) => [
                'id' => $item->id,
                'name' => $item->displayName(),
                'serial' => $item->displaySerial(),
                'isRegistered' => $item->isRegistered(),
                'assetId' => $item->asset?->public_id,
                'code' => $item->asset?->code,
                'isUrgent' => $item->is_urgent,
                'notes' => $item->notes,
                'receptionId' => $item->reception?->public_id,
            ])->all(),
            'can' => [
                'update' => $user->can('update', $shipment),
                'submit' => $user->can('submit', $shipment),
                'review' => $user->can('review', $shipment),
                'delete' => $user->can('delete', $shipment),
            ],
            // Sólo las piezas del propio cliente pueden declararse en un envío.
            'availableAssets' => Inertia::defer(fn () => $user->can('update', $shipment)
                ? Asset::query()
                    ->forOrganization($shipment->organization_id)
                    ->orderBy('description')
                    ->limit(500)
                    ->get(['public_id', 'description', 'serial_number', 'code'])
                    ->map(fn (Asset $a) => [
                        'id' => $a->public_id,
                        'label' => trim($a->description.' · '.($a->serial_number ?? 'sin serie')),
                    ])
                : []),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Shipment::class);

        $user = $request->user();

        $data = $request->validate([
            'client_reference' => ['required', 'string', 'max:60'],
            'expected_at' => ['required', 'date', 'after_or_equal:today'],
            'contact_name' => ['required', 'string', 'min:3', 'max:120'],
            'contact_phone' => ['nullable', 'string', 'max:40', 'regex:/^[0-9+()\\s\\-]+$/'],
            'carrier' => ['nullable', 'string', 'max:120'],
            'origin' => ['nullable', 'string', 'max:160'],
            'notes' => ['nullable', 'string', 'max:500'],
        ], [
            'client_reference.required' => 'Indique su remito o referencia interna.',
            'expected_at.required' => 'Indique cuándo estima que llega al taller.',
            'expected_at.after_or_equal' => 'La fecha estimada no puede ser anterior a hoy.',
            'contact_name.required' => 'Indique quién envía.',
            'contact_phone.regex' => 'El teléfono sólo admite números, espacios y los signos + ( ) -',
        ]);

        $shipment = Shipment::create([
            // La empresa y la referencia interna no se piden: las sabe el sistema.
            'organization_id' => $user->organization_id,
            'reference' => $this->nextReference($user->organization_id),
            'client_reference' => $data['client_reference'],
            'expected_at' => $data['expected_at'],
            'contact_name' => $data['contact_name'],
            'contact_phone' => $data['contact_phone'] ?? null,
            'carrier' => $data['carrier'] ?? null,
            'origin' => $data['origin'] ?? null,
            'notes' => $data['notes'] ?? null,
            'created_by' => $user->getKey(),
        ]);

        ActivityLogger::log('shipment.created', $shipment, "Envío {$shipment->reference} creado");

        return redirect()
            ->route('shipments.show', $shipment)
            ->with('success', "Envío {$shipment->reference} creado. Agregue los equipos y remítalo al taller.");
    }

    public function addItem(Request $request, Shipment $shipment): RedirectResponse
    {
        $this->authorize('update', $shipment);

        $data = $request->validate([
            'asset_public_id' => ['nullable', 'uuid'],
            'declared_description' => ['nullable', 'string', 'max:180'],
            'declared_serial' => ['nullable', 'string', 'max:120'],
            'is_urgent' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:200'],
        ]);

        // O se elige una pieza registrada, o se declara una nueva.
        if (empty($data['asset_public_id']) && empty($data['declared_description'])) {
            return back()->withErrors([
                'declared_description' => 'Elija una pieza registrada o describa la que envía.',
            ]);
        }

        $assetId = null;

        if (! empty($data['asset_public_id'])) {
            // forOrganization impide declarar una pieza de otra empresa.
            $asset = Asset::query()
                ->forOrganization($shipment->organization_id)
                ->where('public_id', $data['asset_public_id'])
                ->first();

            if ($asset === null) {
                return back()->withErrors(['asset_public_id' => 'La pieza seleccionada no existe.']);
            }

            $assetId = $asset->getKey();
        }

        $shipment->items()->create([
            'asset_id' => $assetId,
            'declared_description' => $data['declared_description'] ?? null,
            'declared_serial' => $data['declared_serial'] ?? null,
            'is_urgent' => $data['is_urgent'] ?? false,
            'notes' => $data['notes'] ?? null,
        ]);

        return back()->with('success', 'Equipo agregado al envío.');
    }

    public function removeItem(Shipment $shipment, int $item): RedirectResponse
    {
        $this->authorize('update', $shipment);

        $shipment->items()->whereKey($item)->delete();

        return back()->with('success', 'Equipo quitado del envío.');
    }

    public function submit(Shipment $shipment): RedirectResponse
    {
        $this->authorize('submit', $shipment);

        $shipment->forceFill([
            'status' => Shipment::STATUS_SUBMITTED,
            'submitted_at' => now(),
        ])->save();

        ActivityLogger::log(
            'shipment.submitted',
            $shipment,
            "Envío {$shipment->reference} remitido al taller"
        );

        return back()->with('success', 'Envío remitido al taller. Queda pendiente de recepción hasta que llegue al taller.');
    }

    public function review(Request $request, Shipment $shipment, AuthorizeShipment $action): RedirectResponse
    {
        $this->authorize('review', $shipment);

        $data = $request->validate([
            'decision' => ['required', Rule::in(['authorize', 'reject'])],
            'review_notes' => ['nullable', 'string', 'max:500'],
            'promised_at' => ['nullable', 'date', 'after_or_equal:today'],
        ], [
            'decision.required' => 'Indique si le da recepción o lo rechaza.',
        ]);

        if ($data['decision'] === 'reject') {
            if (blank($data['review_notes'] ?? null)) {
                return back()->withErrors(['review_notes' => 'Indique el motivo del rechazo.']);
            }

            $action->reject($shipment, $data['review_notes']);

            return back()->with('success', 'Envío rechazado. El cliente verá el motivo.');
        }

        $action->authorize($shipment, $data['review_notes'] ?? null, $data['promised_at'] ?? null);

        return back()->with('success', 'Envío recibido. Los equipos quedan en Pendientes del taller.');
    }

    /** Sólo para mostrar en el formulario; no reserva el número. */
    private function previewReference(int $organizationId): string
    {
        $year = now()->format('Y');

        $last = Shipment::query()
            ->forOrganization($organizationId)
            ->where('reference', 'like', "ENV-{$year}-%")
            ->orderByDesc('reference')
            ->value('reference');

        return sprintf('ENV-%s-%04d', $year, $last ? ((int) Str::afterLast($last, '-')) + 1 : 1);
    }

    /** Correlativo por organización, con reintento ante colisión. */
    private function nextReference(int $organizationId): string
    {
        $year = now()->format('Y');

        return DB::transaction(function () use ($organizationId, $year) {
            $last = Shipment::query()
                ->forOrganization($organizationId)
                ->where('reference', 'like', "ENV-{$year}-%")
                ->lockForUpdate()
                ->orderByDesc('reference')
                ->value('reference');

            $next = $last ? ((int) Str::afterLast($last, '-')) + 1 : 1;

            return sprintf('ENV-%s-%04d', $year, $next);
        });
    }
}
