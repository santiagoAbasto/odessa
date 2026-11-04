<?php

namespace App\Http\Controllers;

use App\Enums\AssetStatus;
use App\Models\Asset;
use App\Models\AssetSet;
use App\Support\TableQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Sets: agrupaciones de piezas que se despliegan como un equipo completo. */
class AssetSetController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', AssetSet::class);

        $filters = TableQuery::filters($request, ['search', 'organization', 'status', 'serial', 'code']);

        /*
         * Los sets son los equipos marcados como tales («esto será un set de
         * equipos»), no todos los equipos: pedido de Daniel, 04/11/2026. Cada
         * uno lleva sus piezas, y se abre en su ficha, que es donde se arma.
         */
        $sets = Asset::query()
            ->sets()
            ->with(['organization:id,name', 'catalogItem:id,description'])
            ->withCount('components')
            ->when($filters['search'] ?? null, fn (Builder $q, string $v) => $q->matching($v))
            ->tap(fn (Builder $q) => TableQuery::scopeToOrganization($q, $filters['organization'] ?? null))
            // Con las piezas dentro, el estado del set es el efectivo: el peor de las suyas.
            ->when($filters['status'] ?? null, fn (Builder $q, string $v) => $q
                ->whereRaw('COALESCE(assets.composite_status, assets.status) = ?', [$v]))
            /*
             * Serie y código son de la PIEZA, no del set: se busca el set que
             * CONTIENE esa pieza. Es como realmente se usa —aparece un número
             * de serie y hay que ubicar a qué conjunto pertenece.
             */
            ->when($filters['serial'] ?? null, fn (Builder $q, string $v) => $q
                ->whereHas('components', fn (Builder $a) => $a->where('serial_number', 'like', TableQuery::like($v))))
            ->when($filters['code'] ?? null, fn (Builder $q, string $v) => $q
                ->whereHas('components', fn (Builder $a) => $a->where('code', 'like', TableQuery::like($v))))
            ->orderBy('assets.description')
            ->paginate(TableQuery::perPage($request))
            ->withQueryString()
            ->through(fn (Asset $a) => [
                'id' => $a->public_id,
                'name' => $a->displayName(),
                'organization' => $a->organization?->name,
                'assetsCount' => $a->components_count,
                'status' => $a->effectiveStatus()->value,
                'statusLabel' => $a->effectiveStatus()->label(),
                'statusTone' => $a->effectiveStatus()->tone(),
                'href' => "/activos/{$a->public_id}",
                'notes' => null,
            ]);

        return Inertia::render('sets/index', [
            'sets' => $sets,
            'filters' => $filters,
            'showOrganization' => ! $request->user()->isClientUser(),
            'organizationOptions' => TableQuery::organizationOptions($request),
            'statusOptions' => AssetStatus::options(),
            'canCreate' => $request->user()->can('create', Asset::class),
            // Agrupaciones del sistema anterior: no se pierden, pero ya no se crean así.
            'legacySets' => AssetSet::query()
                ->with('organization:id,name')
                ->withCount('assets')
                ->orderBy('name')
                ->limit(50)
                ->get()
                ->map(fn (AssetSet $s) => [
                    'id' => $s->public_id,
                    'name' => $s->name,
                    'organization' => $s->organization?->name,
                    'assetsCount' => $s->assets_count,
                    'notes' => $s->notes,
                    'href' => "/sets/{$s->public_id}",
                ])->all(),
        ]);
    }

    public function show(AssetSet $set): Response
    {
        $this->authorize('view', $set);

        $set->load(['assets.catalogItem:id,description,part_number', 'assets.currentReport:id,public_id,number']);

        // Composición del estado del set: un set está listo sólo si todas sus
        // piezas están aptas. Es la lectura operativa que interesa al cliente.
        $counts = $set->assets->groupBy(fn ($a) => $a->status->value)->map->count();

        return Inertia::render('sets/show', [
            'set' => [
                'id' => $set->public_id,
                'name' => $set->name,
                'notes' => $set->notes,
                'assetsCount' => $set->assets->count(),
                'readiness' => [
                    'fit' => (int) ($counts['fit'] ?? 0),
                    'expiring' => (int) ($counts['expiring'] ?? 0),
                    'unfit' => (int) ($counts['unfit'] ?? 0),
                    'unknown' => (int) ($counts['unknown'] ?? 0),
                ],
            ],
            'assets' => $set->assets->map(fn ($a) => [
                'id' => $a->public_id,
                'position' => $a->pivot->position,
                'name' => $a->displayName(),
                'serialNumber' => $a->serial_number,
                'code' => $a->code,
                'partNumber' => $a->catalogItem?->part_number,
                'reportNumber' => $a->currentReport?->number,
                'expiresAt' => $a->expires_at?->toDateString(),
                'status' => $a->status->value,
                'statusLabel' => $a->status->label(),
                'statusTone' => $a->status->tone(),
            ])->all(),
        ]);
    }
}
