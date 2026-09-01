<?php

namespace App\Policies;

use App\Models\Asset;
use App\Models\User;
use App\Support\Permissions;

/**
 * Autorización sobre activos.
 *
 * Doble barrera: el permiso responde "¿qué puede hacer?" y
 * `canAccessOrganization` responde "¿sobre los datos de quién?".
 * El global scope ya filtra las listas, pero las Policies deben seguir
 * comprobando la organización para que un cambio futuro en el scope no
 * abra un agujero silencioso.
 */
class AssetPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permissions::ASSETS_VIEW);
    }

    public function view(User $user, Asset $asset): bool
    {
        return $user->can(Permissions::ASSETS_VIEW)
            && $user->canAccessOrganization($asset->organization_id);
    }

    public function create(User $user): bool
    {
        return $user->can(Permissions::ASSETS_CREATE);
    }

    public function update(User $user, Asset $asset): bool
    {
        return $user->can(Permissions::ASSETS_UPDATE)
            && $user->canAccessOrganization($asset->organization_id);
    }

    public function delete(User $user, Asset $asset): bool
    {
        return $user->can(Permissions::ASSETS_DELETE)
            && $user->canAccessOrganization($asset->organization_id);
    }

    public function export(User $user): bool
    {
        return $user->can(Permissions::ASSETS_EXPORT);
    }
}
