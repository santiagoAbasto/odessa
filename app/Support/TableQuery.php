<?php

namespace App\Support;

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
}
