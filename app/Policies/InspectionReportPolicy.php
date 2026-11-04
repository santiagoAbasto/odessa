<?php

namespace App\Policies;

use App\Enums\ReportStatus;
use App\Models\InspectionReport;
use App\Models\User;
use App\Support\Permissions;

class InspectionReportPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permissions::REPORTS_VIEW);
    }

    public function view(User $user, InspectionReport $report): bool
    {
        return $user->can(Permissions::REPORTS_VIEW)
            && $user->canAccessOrganization($report->organization_id);
    }

    public function create(User $user): bool
    {
        return $user->can(Permissions::REPORTS_CREATE);
    }

    /**
     * Un borrador o preliminar se edita siempre. Uno FINAL también, pero sólo
     * el personal del taller (pedido de Daniel, 04/11/2026): alguien puede
     * equivocarse, y cada corrección recalcula las piezas y queda en
     * auditoría. Uno anulado es inmutable.
     */
    public function update(User $user, InspectionReport $report): bool
    {
        return $user->can(Permissions::REPORTS_UPDATE)
            && $user->canAccessOrganization($report->organization_id)
            && ($report->status->isEditable() || ($user->isStaff() && $report->status === ReportStatus::Final));
    }

    /** Dar de baja un informe cargado por error. Taller y superadministrador. */
    public function delete(User $user, InspectionReport $report): bool
    {
        return $user->isStaff()
            && $user->can(Permissions::REPORTS_DELETE)
            && $user->canAccessOrganization($report->organization_id);
    }

    public function finalize(User $user, InspectionReport $report): bool
    {
        return $user->can(Permissions::REPORTS_FINALIZE)
            && $user->canAccessOrganization($report->organization_id)
            && $report->status->canTransitionTo(ReportStatus::Final);
    }

    public function cancel(User $user, InspectionReport $report): bool
    {
        return $user->can(Permissions::REPORTS_CANCEL)
            && $user->canAccessOrganization($report->organization_id)
            && $report->status->canTransitionTo(ReportStatus::Cancelled);
    }

    public function export(User $user): bool
    {
        return $user->can(Permissions::REPORTS_EXPORT);
    }
}
