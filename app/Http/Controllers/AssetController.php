<?php

namespace App\Http\Controllers;

use App\Actions\RecalculateAssetStatus;
use App\Enums\AssetStatus;
use App\Models\Asset;
use App\Models\CatalogItem;
use App\Models\Connection;
use App\Models\Document;
use App\Models\Manufacturer;
use App\Models\Organization;
use App\Models\PressureRating;
use App\Models\Service;
use App\Support\ActivityLogger;
use App\Support\AssetTimeline;
use App\Support\Permissions;
use App\Support\SpreadsheetExport;
use App\Support\TableQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Activos del cliente: partes y genéricos.
 *
 * Reemplaza las pantallas `/clientes-partes` y `/clientes-genericos` del
 * sistema auditado, unificadas en un solo listado con un conmutador de tipo.
 */
class AssetController extends Controller
{
    /** Alias público => columna real. Evita exponer el esquema en la URL. */
    private const SORTABLE = [
        'description' => 'assets.description',
        'code' => 'assets.code',
        'serial' => 'assets.serial_number',
        'client_reference' => 'assets.client_reference',
        'expires_at' => 'assets.expires_at',
        'status' => 'assets.status',
        'brand' => 'assets.brand',
        'model' => 'assets.model',
    ];

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Asset::class);

        $kind = $request->query('kind') === Asset::KIND_GENERIC
            ? Asset::KIND_GENERIC
            : Asset::KIND_PART;

        $filters = TableQuery::filters($request, [
            'search', 'status', 'serial', 'code', 'client_reference',
            'report_number', 'expires_from', 'expires_to', 'brand', 'model',
            'organization',
        ]);

        $query = Asset::query()
            ->where('assets.kind', $kind)
            ->with([
                'catalogItem:id,description,part_number',
                'currentReport:id,number,public_id',
                'organization:id,name',
            ])
            ->select('assets.*');

        $this->applyFilters($query, $filters);

        $assets = TableQuery::sort($query, $request, self::SORTABLE, 'expires_at', 'asc')
            ->paginate(TableQuery::perPage($request))
            ->withQueryString()
            ->through(fn (Asset $asset) => $this->toRow($asset));

        return Inertia::render('assets/index', [
            'kind' => $kind,
            'assets' => $assets,
            'filters' => $filters,
            'sort' => ['column' => $request->query('sort', 'expires_at'), 'direction' => $request->query('direction', 'asc')],
            'statusOptions' => AssetStatus::options(),
            /*
             * Un cliente ve sólo lo suyo, así que la columna de empresa le
             * sobra. Para Odessa es imprescindible: sin ella el inventario
             * de todos los clientes se ve como una sola bolsa.
             */
            'showOrganization' => ! $request->user()->isClientUser(),
            'organizationOptions' => $request->user()->isClientUser() ? [] : Organization::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['public_id', 'name'])
                ->map(fn (Organization $o) => ['value' => $o->public_id, 'label' => $o->name])
                ->all(),
            'summary' => Inertia::defer(fn () => $this->summary($kind)),
        ]);
    }

    /**
     * Descarga en Excel de lo que el usuario está viendo.
     *
     * Reutiliza exactamente los mismos filtros y el mismo orden que la tabla:
     * si exportara otra cosa que lo que hay en pantalla, la planilla no
     * serviría para justificar nada. El aislamiento por empresa lo aplica el
     * global scope, igual que en el listado, así que un cliente sólo puede
     * bajarse lo suyo.
     */
    public function export(Request $request): StreamedResponse
    {
        $this->authorize('viewAny', Asset::class);

        $kind = $request->query('kind') === Asset::KIND_GENERIC
            ? Asset::KIND_GENERIC
            : Asset::KIND_PART;

        $filters = TableQuery::filters($request, [
            'search', 'status', 'serial', 'code', 'client_reference',
            'report_number', 'expires_from', 'expires_to', 'brand', 'model',
        ]);

        $query = Asset::query()
            ->where('assets.kind', $kind)
            ->with(['catalogItem:id,description,part_number', 'currentReport:id,number,inspected_at', 'organization:id,name'])
            ->select('assets.*');

        $this->applyFilters($query, $filters);

        $query = TableQuery::sort($query, $request, self::SORTABLE, 'expires_at', 'asc');

        ActivityLogger::log(
            action: 'asset.exported',
            description: sprintf('Exportó %s a Excel', $kind === Asset::KIND_PART ? 'piezas' : 'equipos'),
        );

        $user = $request->user();

        return SpreadsheetExport::download(
            filename: SpreadsheetExport::filename(
                $kind === Asset::KIND_PART ? 'piezas' : 'equipos',
                $user->organization?->name,
            ),
            headings: [
                'Código', 'Descripción', 'Número de serie', 'Referencia del cliente',
                'Marca', 'Modelo', 'Estado', 'Vence', 'Días para vencer',
                'Último FMR', 'Última inspección', 'Empresa',
            ],
            rows: fn () => $this->exportRows($query),
        );
    }

    /**
     * Se recorre por tandas con `lazy()`: un cliente con miles de piezas no
     * debe cargar la colección entera en memoria para bajarse una planilla.
     *
     * @return \Generator<int, array<int, mixed>>
     */
    private function exportRows(Builder $query): \Generator
    {
        foreach ($query->lazy(500) as $asset) {
            yield [
                $asset->code,
                $asset->displayName(),
                $asset->serial_number,
                $asset->client_reference,
                $asset->brand,
                $asset->model,
                $asset->status->label(),
                $asset->expires_at?->format('d/m/Y'),
                $asset->expires_at ? today()->diffInDays($asset->expires_at, false) : null,
                $asset->currentReport?->number,
                $asset->currentReport?->inspected_at?->format('d/m/Y'),
                $asset->organization?->name,
            ];
        }
    }

    public function show(Request $request, Asset $asset): Response
    {
        $this->authorize('view', $asset);

        $asset->load([
            'catalogItem.manufacturer', 'manufacturer', 'testPressure', 'workingPressure',
            'service', 'connectionTop', 'connectionBottom', 'connectionSide1', 'connectionSide2',
            'currentReport', 'organization:id,name', 'parent:id,public_id,description,serial_number',
            'components:id,public_id,parent_id,description,serial_number,code,status,composite_status,expires_at,composite_expires_at',
        ]);

        // Bitácora: todos los FMR donde apareció la pieza, del más nuevo al más viejo.
        $history = $asset->inspectionLines()
            ->with(['inspectionReport:id,public_id,number,inspected_at,status', 'inspectionReport.documents', 'service:id,code,name'])
            ->join('inspection_reports', 'inspection_reports.id', '=', 'inspection_report_asset.inspection_report_id')
            ->orderByDesc('inspection_reports.inspected_at')
            ->orderByDesc('inspection_report_asset.id')
            ->select('inspection_report_asset.*')
            ->get()
            ->map(fn ($line) => [
                'id' => $line->id,
                'result' => $line->result->value,
                'resultLabel' => $line->result->label(),
                'resultTone' => $line->result->tone(),
                'determinesFitness' => $line->result->determinesFitness(),
                'expiresAt' => $line->expires_at?->toDateString(),
                'seal' => $line->seal,
                'findings' => $line->findings,
                'service' => $line->service?->label(),
                'report' => [
                    'id' => $line->inspectionReport->public_id,
                    'number' => $line->inspectionReport->number,
                    'inspectedAt' => $line->inspectionReport->inspected_at?->toDateString(),
                    'status' => $line->inspectionReport->status->value,
                    'statusLabel' => $line->inspectionReport->status->label(),
                ],
                'files' => $line->inspectionReport->documents->map(fn ($d) => $d->toPayload())->all(),
            ]);

        ActivityLogger::log('asset.viewed', $asset, "Consulta de la pieza {$asset->displayName()}");

        return Inertia::render('assets/show', [
            'asset' => [
                'id' => $asset->public_id,
                'kind' => $asset->kind,
                'name' => $asset->displayName(),
                // Dueño del equipo. Se muestra en la cabecera de la ficha.
                'organization' => $asset->organization?->name,
                'description' => $asset->description,
                'workshopDescription' => $asset->workshop_description,
                'serialNumber' => $asset->serial_number,
                    'clientReference' => $asset->client_reference,
                'manufacturerReference' => $asset->manufacturer_reference,
                'partNumber' => $asset->catalogItem?->part_number,
                'catalogDescription' => $asset->catalogItem?->description,
                'brand' => $asset->brand,
                'model' => $asset->model,
                'identifier' => $asset->identifier,
                'manufacturer' => $asset->manufacturer?->name,
                'testPressure' => $asset->testPressure?->label,
                'workingPressure' => $asset->workingPressure?->label,
                'service' => $asset->service?->label(),
                'connections' => array_filter([
                    'Superior' => $asset->connectionTop?->label,
                    'Inferior' => $asset->connectionBottom?->label,
                    'Lateral 1' => $asset->connectionSide1?->label,
                    'Lateral 2' => $asset->connectionSide2?->label,
                ]),
                'status' => $asset->status->value,
                'statusLabel' => $asset->status->label(),
                'statusTone' => $asset->status->tone(),
                'expiresAt' => $asset->expires_at?->toDateString(),
                'daysToExpiry' => $asset->expires_at ? (int) today()->diffInDays($asset->expires_at, false) : null,
                'currentReport' => $asset->currentReport ? [
                    'id' => $asset->currentReport->public_id,
                    'number' => $asset->currentReport->number,
                ] : null,
                /*
                 * Estado con el que se decide si el equipo se puede desplegar.
                 * Para un conjunto es el peor entre el suyo y los de sus
                 * componentes: no se despliega a medias.
                 */
                /*
                 * Identificadores crudos para el formulario de edición. Las
                 * etiquetas de arriba sirven para mostrar; estos, para editar.
                 */
                'catalogItemId' => $asset->catalog_item_id,
                'manufacturerId' => $asset->manufacturer_id,
                'testPressureId' => $asset->test_pressure_id,
                'workingPressureId' => $asset->working_pressure_id,
                'serviceId' => $asset->service_id,
                'connectionTopId' => $asset->connection_top_id,
                'connectionBottomId' => $asset->connection_bottom_id,
                'connectionSide1Id' => $asset->connection_side_1_id,
                'connectionSide2Id' => $asset->connection_side_2_id,

                'isComposite' => $asset->components->isNotEmpty(),
                'effectiveStatus' => $asset->effectiveStatus()->value,
                'effectiveStatusLabel' => $asset->effectiveStatus()->label(),
                'effectiveStatusTone' => $asset->effectiveStatus()->tone(),
                'effectiveExpiresAt' => $asset->effectiveExpiresAt()?->toDateString(),
                'parent' => $asset->parent ? [
                    'id' => $asset->parent->public_id,
                    'name' => $asset->parent->description,
                    'serial' => $asset->parent->serial_number,
                ] : null,
                'components' => $asset->components->map(fn (Asset $c) => [
                    'id' => $c->public_id,
                    'name' => $c->description,
                    'serial' => $c->serial_number ?? $c->code,
                    'status' => $c->effectiveStatus()->value,
                    'statusLabel' => $c->effectiveStatus()->label(),
                    'statusTone' => $c->effectiveStatus()->tone(),
                    'expiresAt' => $c->effectiveExpiresAt()?->toDateString(),
                    // Marca las piezas que están bloqueando al conjunto: no
                    // alcanza con decir que no sirve, hay que decir por cuál.
                    'isBlocking' => $c->effectiveStatus()->severity() > AssetStatus::Fit->severity(),
                ])->all(),
            ],
            'history' => $history,
            /*
             * Hilo completo de trazabilidad: envíos, recepciones, hitos del
             * taller, inspecciones, documentos y auditoría, en una sola línea
             * de tiempo. Diferido porque puede traer cientos de hitos y no es
             * lo primero que se mira al abrir la ficha.
             */
            'timeline' => Inertia::defer(fn () => (new AssetTimeline($asset))->build()),
            // Documentos propios de la pieza, independientes de cualquier FMR.
            'documents' => $asset->documents()->with('uploader:id,name')->get()
                ->map(fn ($d) => $d->toPayload())->all(),
            'categories' => collect(Document::CATEGORIES)
                ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
                ->values(),
            'can' => [
                'upload' => $request->user()->can(Permissions::DOCUMENTS_UPLOAD),
                'deleteDocuments' => $request->user()->can(Permissions::DOCUMENTS_DELETE),
                'edit' => $request->user()->can('update', $asset),
            ],
            // Catálogos del formulario. Diferidos: sólo hacen falta al editar.
            'catalogs' => Inertia::defer(fn () => $this->editCatalogs($asset)),
        ]);
    }

    /**
     * Edición de la ficha técnica.
     *
     * La habilita el negocio para superadministrador y taller: son quienes
     * conocen el equipo. Cambiar el tipo de equipo no es cosmético —determina
     * el tiempo de taller y, con él, la fecha límite de envío—, así que
     * después de guardar se recalcula el estado.
     *
     * El cambio queda en la auditoría con el antes y el después: es la única
     * forma de explicar más tarde por qué a un equipo le cambió el plazo.
     */
    public function update(Request $request, Asset $asset, RecalculateAssetStatus $recalculate): RedirectResponse
    {
        $this->authorize('update', $asset);

        $data = $request->validate([
            'description' => ['required', 'string', 'min:3', 'max:180'],
            'workshop_description' => ['nullable', 'string', 'max:180'],
            'serial_number' => ['nullable', 'string', 'max:120'],
            'client_reference' => ['nullable', 'string', 'max:120'],
            'manufacturer_reference' => ['nullable', 'string', 'max:120'],
            'brand' => ['nullable', 'string', 'max:120'],
            'model' => ['nullable', 'string', 'max:120'],
            'identifier' => ['nullable', 'string', 'max:120'],
            'catalog_item_id' => ['nullable', 'integer', 'exists:catalog_items,id'],
            'manufacturer_id' => ['nullable', 'integer', 'exists:manufacturers,id'],
            'test_pressure_id' => ['nullable', 'integer', 'exists:pressure_ratings,id'],
            'working_pressure_id' => ['nullable', 'integer', 'exists:pressure_ratings,id'],
            'service_id' => ['nullable', 'integer', 'exists:services,id'],
            'connection_top_id' => ['nullable', 'integer', 'exists:connections,id'],
            'connection_bottom_id' => ['nullable', 'integer', 'exists:connections,id'],
            'connection_side_1_id' => ['nullable', 'integer', 'exists:connections,id'],
            'connection_side_2_id' => ['nullable', 'integer', 'exists:connections,id'],
            // El conjunto del que esta pieza es componente.
            'parent_public_id' => ['nullable', 'uuid'],
        ], [
            'description.required' => 'La descripción no puede quedar vacía.',
        ]);

        $parentId = $this->resolveParent($asset, $data['parent_public_id'] ?? null);

        if ($parentId === false) {
            return back()->withErrors([
                'parent_public_id' => 'El conjunto elegido no existe o no pertenece a esta empresa.',
            ]);
        }

        unset($data['parent_public_id']);
        $data['parent_id'] = $parentId;

        $before = $asset->only(array_keys($data));

        $asset->update($data);

        // El tipo de equipo puede haber cambiado, y con él el tiempo de taller.
        $recalculate($asset->refresh());

        ActivityLogger::log(
            action: 'asset.updated',
            subject: $asset,
            description: "Se modificó la ficha de {$asset->displayName()}",
            old: $before,
            new: $data,
        );

        return back()->with('success', 'Ficha técnica actualizada.');
    }

    /**
     * Resuelve el conjunto padre.
     *
     * Devuelve `false` si el conjunto no existe o es de otra empresa, y `null`
     * si la pieza queda suelta. Una pieza no puede ser su propio conjunto:
     * dejaría el recálculo girando sobre sí mismo.
     */
    private function resolveParent(Asset $asset, ?string $parentPublicId): int|false|null
    {
        if (blank($parentPublicId)) {
            return null;
        }

        $parent = Asset::query()
            ->forOrganization($asset->organization_id)
            ->where('public_id', $parentPublicId)
            ->first();

        if ($parent === null || $parent->getKey() === $asset->getKey()) {
            return false;
        }

        return $parent->getKey();
    }

    /**
     * Opciones de los desplegables de la ficha técnica.
     *
     * Los conjuntos candidatos se limitan a piezas de la misma empresa y se
     * excluye la propia: una pieza no puede ser su propio conjunto.
     *
     * @return array<string, mixed>
     */
    private function editCatalogs(Asset $asset): array
    {
        $options = fn ($rows, string $labelColumn) => $rows
            ->map(fn ($row) => ['value' => $row->getKey(), 'label' => $row->{$labelColumn}])
            ->all();

        return [
            'catalogItems' => CatalogItem::query()->where('kind', $asset->kind)
                ->orderBy('description')->get(['id', 'description'])
                ->map(fn (CatalogItem $c) => ['value' => $c->getKey(), 'label' => $c->description])->all(),
            'manufacturers' => $options(Manufacturer::orderBy('name')->get(['id', 'name']), 'name'),
            'pressures' => $options(PressureRating::orderBy('label')->get(['id', 'label']), 'label'),
            'connections' => $options(Connection::orderBy('label')->get(['id', 'label']), 'label'),
            'services' => Service::query()->where('is_active', true)->orderBy('name')
                ->get(['id', 'name', 'code'])
                ->map(fn (Service $s) => ['value' => $s->getKey(), 'label' => $s->label()])->all(),
            'possibleParents' => Asset::query()
                ->forOrganization($asset->organization_id)
                ->whereKeyNot($asset->getKey())
                ->orderBy('description')
                ->limit(500)
                ->get(['id', 'public_id', 'description', 'serial_number'])
                ->map(fn (Asset $a) => [
                    'value' => $a->public_id,
                    'label' => trim($a->description.' · '.($a->serial_number ?? 'sin serie')),
                ])->all(),
        ];
    }

    private function applyFilters(Builder $query, array $filters): void
    {
        $query
            // Sólo tiene efecto para usuarios de Odessa: a un cliente el global
            // scope ya lo dejó viendo únicamente lo suyo.
            ->when($filters['organization'] ?? null, fn (Builder $q, string $v) => $q
                ->whereHas('organization', fn (Builder $o) => $o->where('public_id', $v)))
            ->when($filters['search'] ?? null, function (Builder $q, string $term) {
                $like = TableQuery::like($term);
                $q->where(function (Builder $inner) use ($like) {
                    $inner->where('assets.description', 'like', $like)
                        ->orWhere('assets.serial_number', 'like', $like)
                        ->orWhere('assets.code', 'like', $like)
                        ->orWhere('assets.client_reference', 'like', $like)
                        ->orWhere('assets.workshop_description', 'like', $like)
                        ->orWhereHas('catalogItem', fn (Builder $c) => $c
                            ->where('description', 'like', $like)
                            ->orWhere('part_number', 'like', $like));
                });
            })
            ->when($filters['status'] ?? null, fn (Builder $q, string $s) => $q->where('assets.status', $s))
            ->when($filters['serial'] ?? null, fn (Builder $q, string $v) => $q->where('assets.serial_number', 'like', TableQuery::like($v)))
            ->when($filters['code'] ?? null, fn (Builder $q, string $v) => $q->where('assets.code', 'like', TableQuery::like($v)))
            ->when($filters['client_reference'] ?? null, fn (Builder $q, string $v) => $q->where('assets.client_reference', 'like', TableQuery::like($v)))
            ->when($filters['brand'] ?? null, fn (Builder $q, string $v) => $q->where('assets.brand', 'like', TableQuery::like($v)))
            ->when($filters['model'] ?? null, fn (Builder $q, string $v) => $q->where('assets.model', 'like', TableQuery::like($v)))
            ->when($filters['report_number'] ?? null, fn (Builder $q, string $v) => $q
                ->whereHas('currentReport', fn (Builder $r) => $r->where('number', 'like', TableQuery::like($v))))
            ->when($filters['expires_from'] ?? null, fn (Builder $q, string $v) => $q->whereDate('assets.expires_at', '>=', $v))
            ->when($filters['expires_to'] ?? null, fn (Builder $q, string $v) => $q->whereDate('assets.expires_at', '<=', $v));
    }

    private function toRow(Asset $asset): array
    {
        return [
            'id' => $asset->public_id,
            'name' => $asset->displayName(),
            // De quién es el equipo. Sin esto, desde Odessa el listado mezcla
            // las piezas de todos los clientes sin forma de distinguirlas.
            'organization' => $asset->organization?->name,
            'partNumber' => $asset->catalogItem?->part_number,
            'serialNumber' => $asset->serial_number,
            'code' => $asset->code,
            'clientReference' => $asset->client_reference,
            'brand' => $asset->brand,
            'model' => $asset->model,
            'identifier' => $asset->identifier,
            'reportNumber' => $asset->currentReport?->number,
            'reportId' => $asset->currentReport?->public_id,
            'expiresAt' => $asset->expires_at?->toDateString(),
            'status' => $asset->status->value,
            'statusLabel' => $asset->status->label(),
            'statusTone' => $asset->status->tone(),
        ];
    }

    /**
     * Conteo por estado para las tarjetas de resumen del listado.
     * Se envía diferido para que la tabla pinte sin esperar los agregados.
     */
    private function summary(string $kind): array
    {
        $counts = Asset::query()
            ->where('kind', $kind)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return collect(AssetStatus::cases())
            ->map(fn (AssetStatus $s) => [
                'status' => $s->value,
                'label' => $s->label(),
                'tone' => $s->tone(),
                'count' => (int) ($counts[$s->value] ?? 0),
            ])
            ->all();
    }
}
