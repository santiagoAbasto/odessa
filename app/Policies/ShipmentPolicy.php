<?php

namespace App\Policies;

use App\Models\Shipment;
use App\Models\User;
use App\Support\Permissions;

class ShipmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permissions::SHIPMENTS_VIEW);
    }

    public function view(User $user, Shipment $shipment): bool
    {
        return $user->can(Permissions::SHIPMENTS_VIEW)
            && $user->canAccessOrganization($shipment->organization_id);
    }

    public function create(User $user): bool
    {
        // El cliente pre-carga su propia declaración; el taller puede cargar
        // un envío por una organización cuando el cliente no lo hace. A qué
        // empresa va lo resuelve el controlador: el cliente, la suya; el
        // taller, la que elige.
        return $user->can(Permissions::SHIPMENTS_CREATE);
    }

    /** Un envío enviado o ya revisado deja de ser editable por el cliente. */
    public function update(User $user, Shipment $shipment): bool
    {
        return $user->can(Permissions::SHIPMENTS_CREATE)
            && $user->canAccessOrganization($shipment->organization_id)
            && $shipment->isEditable();
    }

    public function submit(User $user, Shipment $shipment): bool
    {
        return $this->update($user, $shipment) && $shipment->items()->exists();
    }

    public function delete(User $user, Shipment $shipment): bool
    {
        return $this->update($user, $shipment);
    }

    /** Autorizar o rechazar es competencia exclusiva del taller. */
    public function review(User $user, Shipment $shipment): bool
    {
        return $user->can(Permissions::SHIPMENTS_AUTHORIZE)
            && $user->canAccessOrganization($shipment->organization_id)
            && $shipment->isPending();
    }
}
