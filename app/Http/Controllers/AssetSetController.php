<?php

namespace App\Http\Controllers;

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

        $filters = TableQuery::filters($request, ['search']);

        $sets = AssetSet::query()
            ->with('organization:id,name')
            ->withCount('assets')
            ->when($filters['search'] ?? null, fn (Builder $q, string $v) => $q
                ->where('name', 'like', TableQuery::like($v)))
            ->orderBy('name')
            ->paginate(TableQuery::perPage($request))
            ->withQueryString()
            ->through(fn (AssetSet $s) => [
                'id' => $s->public_id,
                'name' => $s->name,
                'organization' => $s->organization?->name,
                'assetsCount' => $s->assets_count,
                'notes' => $s->notes,
            ]);

        return Inertia::render('sets/index', [
            'sets' => $sets,
            'filters' => $filters,
            'showOrganization' => ! $request->user()->isClientUser(),
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
