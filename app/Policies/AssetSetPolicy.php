<?php

namespace App\Policies;

use App\Models\AssetSet;
use App\Models\User;
use App\Support\Permissions;

class AssetSetPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permissions::SETS_VIEW);
    }

    public function view(User $user, AssetSet $set): bool
    {
        return $user->can(Permissions::SETS_VIEW)
            && $user->canAccessOrganization($set->organization_id);
    }

    public function create(User $user): bool
    {
        return $user->can(Permissions::SETS_MANAGE);
    }

    public function update(User $user, AssetSet $set): bool
    {
        return $user->can(Permissions::SETS_MANAGE)
            && $user->canAccessOrganization($set->organization_id);
    }

    public function delete(User $user, AssetSet $set): bool
    {
        return $this->update($user, $set);
    }
}
