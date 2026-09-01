<?php

namespace App\Policies;

use App\Models\User;
use App\Support\Permissions;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permissions::USERS_VIEW);
    }

    public function view(User $user, User $target): bool
    {
        return $user->can(Permissions::USERS_VIEW)
            && ($user->isStaff() || $user->organization_id === $target->organization_id);
    }

    public function create(User $user): bool
    {
        return $user->can(Permissions::USERS_MANAGE);
    }

    /**
     * Un administrador de cliente sólo gestiona usuarios de su propia empresa,
     * y nunca puede tocar al personal interno de Odessa Oil.
     */
    public function update(User $user, User $target): bool
    {
        if (! $user->can(Permissions::USERS_MANAGE)) {
            return false;
        }

        if ($user->isStaff()) {
            return true;
        }

        return $target->organization_id !== null
            && $user->organization_id === $target->organization_id;
    }

    public function delete(User $user, User $target): bool
    {
        // Nadie se desactiva a sí mismo: evita quedarse sin administradores.
        return $user->getKey() !== $target->getKey() && $this->update($user, $target);
    }

    public function manageRoles(User $user): bool
    {
        return $user->can(Permissions::ROLES_MANAGE);
    }
}
