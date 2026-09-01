<?php

namespace App\Models\Concerns;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

/**
 * Aislamiento multi-tenant.
 *
 * Aplica un scope global que confina TODA consulta a la organización del usuario
 * autenticado. Es la defensa principal contra IDOR: aunque alguien adivine un
 * `public_id` de otra empresa, la consulta no lo encuentra y el binding devuelve 404.
 *
 * Un usuario interno de Odessa Oil (`organization_id === null`) no queda filtrado, pero
 * su acceso sigue gobernado por las Policies. El scope NO sustituye a la
 * autorización: la complementa.
 */
trait BelongsToOrganization
{
    public static function bootBelongsToOrganization(): void
    {
        static::addGlobalScope('organization', function (Builder $builder) {
            $user = Auth::user();

            if (! $user) {
                return;
            }

            if ($user->organization_id !== null) {
                $builder->where($builder->getModel()->qualifyColumn('organization_id'), $user->organization_id);
            }
        });

        // Al crear, la organización se hereda del usuario; nunca del payload.
        static::creating(function ($model) {
            if ($model->organization_id === null && ($user = Auth::user()) && $user->organization_id !== null) {
                $model->organization_id = $user->organization_id;
            }
        });
    }

    /**
     * Escapa del scope de forma explícita y rastreable.
     * Reservado para jobs, comandos de consola y reportes de administración.
     */
    public function scopeAcrossOrganizations(Builder $query): Builder
    {
        return $query->withoutGlobalScope('organization');
    }

    public function scopeForOrganization(Builder $query, Organization|int $organization): Builder
    {
        $id = $organization instanceof Organization ? $organization->getKey() : $organization;

        return $query->withoutGlobalScope('organization')
            ->where($query->getModel()->qualifyColumn('organization_id'), $id);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
