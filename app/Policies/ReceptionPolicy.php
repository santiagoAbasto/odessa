<?php

namespace App\Policies;

use App\Models\Reception;
use App\Models\User;
use App\Support\Permissions;

class ReceptionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permissions::RECEPTIONS_VIEW);
    }

    public function view(User $user, Reception $reception): bool
    {
        return $user->can(Permissions::RECEPTIONS_VIEW)
            && $user->canAccessOrganization($reception->organization_id);
    }

    public function create(User $user): bool
    {
        return $user->can(Permissions::RECEPTIONS_CREATE);
    }

    public function update(User $user, Reception $reception): bool
    {
        return $user->can(Permissions::RECEPTIONS_UPDATE)
            && $user->canAccessOrganization($reception->organization_id)
            && ! $reception->isDispatched();
    }

    /**
     * Cubre todo el trabajo del taller sobre una recepción: empezar,
     * dictaminar, mandar a reparar y dar la reparación por terminada.
     *
     * No exige que falte el dictamen —eso lo valida cada acción, que sabe en
     * qué etapa tiene sentido—; si lo hiciera acá, mandar a reparar algo ya
     * rechazado daría 403 en vez de un mensaje que se entienda.
     */
    public function release(User $user, Reception $reception): bool
    {
        return $user->can(Permissions::RECEPTIONS_RELEASE)
            && $user->canAccessOrganization($reception->organization_id)
            && ! $reception->isDispatched();
    }

    /**
     * Contestar si se autoriza la reparación.
     *
     * Lo puede hacer el cliente desde su panel, o alguien de Odessa cargando
     * la respuesta que recibió por fuera. A diferencia de `release`, acá sí se
     * exige la etapa: fuera de "esperando al cliente" no hay ninguna pregunta
     * abierta que contestar, y el permiso es lo único que un usuario cliente
     * tiene sobre una recepción —conviene que sea estrecho.
     */
    public function authorizeRepair(User $user, Reception $reception): bool
    {
        return $user->can(Permissions::RECEPTIONS_AUTHORIZE_REPAIR)
            && $user->canAccessOrganization($reception->organization_id)
            && $reception->isAwaitingClient();
    }

    /**
     * Deshacer un dictamen cargado por error. Sólo el superadministrador tiene
     * el permiso. No exige la etapa exacta —de eso se encarga la Action, que
     * sabe que sólo un aprobado/rechazado sin consulta ni despacho se deshace—;
     * acá alcanza con cortar lo ya despachado.
     */
    public function revert(User $user, Reception $reception): bool
    {
        return $user->can(Permissions::RECEPTIONS_REVERT)
            && $user->canAccessOrganization($reception->organization_id)
            && ! $reception->isDispatched();
    }

    /**
     * Sale del taller lo aprobado, lo ya reparado, y lo que el cliente
     * decidió no reparar —eso vuelve como está, sin certificar. Lo que no
     * sale es un equipo rechazado sobre el que nadie decidió nada.
     */
    public function dispatchReception(User $user, Reception $reception): bool
    {
        return $user->can(Permissions::RECEPTIONS_DISPATCH)
            && $user->canAccessOrganization($reception->organization_id)
            && $reception->isDispatchable();
    }
}
