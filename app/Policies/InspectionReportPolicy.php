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

    /** Un FMR final o anulado es inmutable: se corrige emitiendo una revisión. */
    public function update(User $user, InspectionReport $report): bool
    {
        return $user->can(Permissions::REPORTS_UPDATE)
            && $user->canAccessOrganization($report->organization_id)
            && $report->status->isEditable();
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
