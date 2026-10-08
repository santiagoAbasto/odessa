<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CatalogItem;
use App\Models\Manufacturer;
use App\Support\ActivityLogger;
use App\Support\Permissions;
use App\Support\TableQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Maestro de productos: el "qué es" de una pieza, compartido entre clientes.
 *
 * Es la lista de la que el cliente elige al declarar un envío, y de la que
 * cuelgan todas las series de todas las empresas: dos clientes pueden tener la
 * misma trampa, cada uno con su número de serie, y las dos apuntan al mismo
 * producto. Eso es lo que permite preguntar "cuántos lubricadores hay en la
 * calle" sin depender de cómo lo escribió cada uno.
 *
 * El alta y la edición quedan sólo para el superadministrador. No es un
 * capricho de permisos: el catálogo existe para filtrar, y un catálogo que
 * cualquiera puede ampliar se llena de tres formas de escribir lo mismo y deja
 * de filtrar nada.
 */
class ProductController extends Controller
{
    private const FILTERS = ['search', 'kind', 'manufacturer', 'unused'];

    private const SORTABLE = ['part_number', 'description', 'kind', 'assets_count'];

    public function index(Request $request): Response
    {
        abort_unless($request->user()->can(Permissions::PRODUCTS_VIEW), 403);

        $filters = TableQuery::filters($request, self::FILTERS);

        $query = CatalogItem::query()
            ->with('manufacturer:id,name')
            ->withCount('assets');

        $this->applyFilters($query, $filters);

        $products = TableQuery::sort($query, $request, self::SORTABLE, 'description', 'asc')
            ->paginate(TableQuery::perPage($request))
            ->withQueryString()
            ->through(fn (CatalogItem $item) => [
                'id' => $item->getKey(),
                'partNumber' => $item->part_number,
                'description' => $item->description,
                'kind' => $item->kind,
                'manufacturerId' => $item->manufacturer_id,
                'manufacturer' => $item->manufacturer?->name,
                'assetsCount' => (int) $item->assets_count,
            ]);

        return Inertia::render('admin/products', [
            'products' => $products,
            'filters' => $filters,
            'sort' => [
                'column' => $request->query('sort', 'description'),
                'direction' => $request->query('direction', 'asc'),
            ],
            'manufacturerOptions' => Manufacturer::query()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Manufacturer $m) => ['value' => (string) $m->getKey(), 'label' => $m->name])
                ->all(),
            'can' => ['manage' => $request->user()->can(Permissions::PRODUCTS_MANAGE)],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can(Permissions::PRODUCTS_MANAGE), 403);

        $data = $this->validated($request);

        $product = CatalogItem::create($data);

        ActivityLogger::log(
            action: 'product.created',
            subject: $product,
            description: "Se creó el producto {$product->description}",
        );

        return back()->with('success', "Producto «{$product->description}» creado.");
    }

    public function update(Request $request, CatalogItem $product): RedirectResponse
    {
        abort_unless($request->user()->can(Permissions::PRODUCTS_MANAGE), 403);

        $data = $this->validated($request, $product);
        $before = $product->only(array_keys($data));

        $product->update($data);

        ActivityLogger::log(
            action: 'product.updated',
            subject: $product,
            description: "Se modificó el producto {$product->description}",
            old: $before,
            new: $data,
        );

        return back()->with('success', "Producto «{$product->description}» actualizado.");
    }

    /**
     * Sólo se puede borrar un producto que no clasifica ningún equipo.
     *
     * Con equipos colgando, borrarlo les borraría de un plumazo el "qué es" y
     * dejaría el historial sin sentido. Para esos casos está editar: si la
     * descripción estaba mal, se corrige y se arregla en todos a la vez.
     */
    public function destroy(Request $request, CatalogItem $product): RedirectResponse
    {
        abort_unless($request->user()->can(Permissions::PRODUCTS_MANAGE), 403);

        if ($product->assets()->exists()) {
            return back()->with('error', 'No se puede borrar: hay equipos clasificados con este producto. Editá la descripción si está mal escrita.');
        }

        $description = $product->description;
        $product->delete();

        ActivityLogger::log(
            action: 'product.deleted',
            subject: $product,
            description: "Se borró el producto {$description}",
        );

        return back()->with('success', "Producto «{$description}» borrado.");
    }

    /** @param array<string, string|null> $filters */
    private function applyFilters(Builder $query, array $filters): void
    {
        $query
            /*
             * Una sola caja que busca por número de parte y por descripción.
             *
             * El negocio trabaja con el número de parte, pero cuando no lo
             * recuerda busca por descripción: tener dos campos separados
             * obligaría a saber de antemano cuál de los dos se sabe.
             */
            ->when($filters['search'] ?? null, function (Builder $q, string $term) {
                $like = TableQuery::like($term);
                $q->where(fn (Builder $inner) => $inner
                    ->where('description', 'like', $like)
                    ->orWhere('part_number', 'like', $like));
            })
            ->when($filters['kind'] ?? null, fn (Builder $q, string $v) => $q->where('kind', $v))
            ->when($filters['manufacturer'] ?? null, fn (Builder $q, string $v) => $q->where('manufacturer_id', $v))
            // Para encontrar lo que se cargó de más y todavía se puede borrar.
            ->when(($filters['unused'] ?? null) === '1', fn (Builder $q) => $q->doesntHave('assets'));
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?CatalogItem $product = null): array
    {
        $data = $request->validate([
            'description' => ['required', 'string', 'min:2', 'max:255'],
            'part_number' => ['nullable', 'string', 'max:255'],
            'kind' => ['required', Rule::in(['part', 'generic'])],
            'manufacturer_id' => ['nullable', 'integer', 'exists:manufacturers,id'],
        ], [
            'description.required' => 'La descripción es obligatoria: es lo que el cliente ve en la lista.',
        ]);

        /*
         * La tabla tiene un único por (kind, description, part_number), pero el
         * choque llega como error 500 y sin explicación. Se comprueba acá para
         * devolverlo sobre el campo, que en una pantalla de carga repetitiva es
         * la diferencia entre corregir y volver a intentar a ciegas.
         */
        $duplicate = CatalogItem::query()
            ->where('kind', $data['kind'])
            ->where('description', $data['description'])
            ->where(fn (Builder $q) => filled($data['part_number'] ?? null)
                ? $q->where('part_number', $data['part_number'])
                : $q->whereNull('part_number'))
            ->when($product !== null, fn (Builder $q) => $q->whereKeyNot($product->getKey()))
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages([
                'description' => 'Ya existe un producto con esa descripción y ese número de parte.',
            ]);
        }

        return $data;
    }
}
