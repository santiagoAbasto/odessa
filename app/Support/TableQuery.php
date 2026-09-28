<?php

namespace App\Support;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Ordenamiento y paginación de listados, siempre del lado del servidor.
 *
 * La columna de orden se valida contra una lista blanca: nunca se interpola
 * texto del usuario en el SQL.
 */
class TableQuery
{
    public const PER_PAGE_OPTIONS = [25, 50, 100];

    /**
     * @param  array<string, string>  $sortable  alias público => columna real
     */
    public static function sort(Builder $query, Request $request, array $sortable, string $default, string $defaultDirection = 'desc'): Builder
    {
        $requested = (string) $request->query('sort', '');
        $column = $sortable[$requested] ?? $sortable[$default] ?? $default;

        $direction = strtolower((string) $request->query('direction', $defaultDirection)) === 'asc' ? 'asc' : 'desc';

        return $query->orderBy($column, $direction);
    }

    public static function perPage(Request $request, int $default = 25): int
    {
        $value = (int) $request->query('per_page', $default);

        return in_array($value, self::PER_PAGE_OPTIONS, true) ? $value : $default;
    }

    /**
     * Estado de los filtros que se devuelve al frontend para repintar los
     * controles y construir los enlaces de paginación.
     *
     * @param  list<string>  $keys
     */
    public static function filters(Request $request, array $keys): array
    {
        $filters = [];

        foreach ($keys as $key) {
            $value = $request->query($key);
            if ($value !== null && $value !== '') {
                $filters[$key] = is_string($value) ? trim($value) : $value;
            }
        }

        return $filters;
    }

    /** Búsqueda parcial segura: escapa los comodines de LIKE. */
    public static function like(?string $term): string
    {
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], (string) $term);

        return '%'.$escaped.'%';
    }

    /**
     * Opciones del filtro por empresa.
     *
     * Vacío para un usuario cliente: todo lo que ve ya es suyo, y ofrecerle un
     * selector con una sola opción —o peor, con los nombres de las demás
     * empresas— no tendría sentido.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public static function organizationOptions(Request $request): array
    {
        if ($request->user()?->isClientUser()) {
            return [];
        }

        return Organization::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['public_id', 'name'])
            ->map(fn (Organization $o) => ['value' => $o->public_id, 'label' => $o->name])
            ->all();
    }

    /** Acota una consulta a la empresa elegida en el filtro. */
    public static function scopeToOrganization(Builder $query, ?string $publicId): Builder
    {
        return $query->when($publicId, fn (Builder $q, string $v) => $q
            ->whereHas('organization', fn (Builder $o) => $o->where('public_id', $v)));
    }
}
