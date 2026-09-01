<?php

namespace App\Models\Concerns;

use Illuminate\Support\Str;

/**
 * Identificador público UUID.
 *
 * El sistema auditado expone IDs autoincrementales en la URL
 * (`/ClientesPartes/view/526`), lo que permite enumerar el inventario completo
 * cambiando un número. Aquí las rutas usan UUID y el ID interno nunca sale.
 */
trait HasPublicId
{
    public static function bootHasPublicId(): void
    {
        static::creating(function ($model) {
            if (empty($model->public_id)) {
                $model->public_id = (string) Str::uuid7();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
