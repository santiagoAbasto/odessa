<?php

namespace App\Policies;

use App\Models\Document;
use App\Models\User;
use App\Support\Permissions;

/**
 * Documentos privados, sean de un FMR o de una pieza.
 *
 * El aislamiento se comprueba contra `organization_id` del propio documento,
 * que se guarda de forma redundante justo para no depender de recorrer la
 * relación polimórfica.
 */
class DocumentPolicy
{
    public function view(User $user, Document $document): bool
    {
        return $this->download($user, $document);
    }

    public function download(User $user, Document $document): bool
    {
        return $user->can(Permissions::DOCUMENTS_VIEW)
            && $user->canAccessOrganization($document->organization_id);
    }

    public function create(User $user): bool
    {
        return $user->can(Permissions::DOCUMENTS_UPLOAD);
    }

    public function delete(User $user, Document $document): bool
    {
        return $user->can(Permissions::DOCUMENTS_DELETE)
            && $user->canAccessOrganization($document->organization_id);
    }
}
