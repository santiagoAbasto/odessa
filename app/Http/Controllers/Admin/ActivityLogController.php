<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Asset;
use App\Models\Organization;
use App\Support\AuditActions;
use App\Support\Permissions;
use App\Support\TableQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Auditoría. Sólo lectura; no se ofrece edición ni borrado por diseño. */
class ActivityLogController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()->can(Permissions::AUDIT_VIEW), 403);

        $actor = $request->user();
        $filters = TableQuery::filters($request, ['search', 'action', 'date_from', 'date_to', 'organization', 'asset']);

        $logs = ActivityLog::query()
            // `subject` es polimórfico: sin cargarlo acá sería una consulta
            // por fila, y con 50 filas por página se nota.
            ->with(['user:id,name,email', 'organization:id,name', 'subject'])
            ->when($actor->isClientUser(), fn (Builder $q) => $q->where('organization_id', $actor->organization_id))
            ->when($filters['action'] ?? null, fn (Builder $q, string $v) => $q->where('action', $v))
            ->when($filters['search'] ?? null, fn (Builder $q, string $v) => $q
                ->where(fn (Builder $i) => $i
                    ->where('description', 'like', TableQuery::like($v))
                    ->orWhereHas('user', fn (Builder $u) => $u->where('email', 'like', TableQuery::like($v)))))
            // Por empresa: "qué se hizo con las cosas de este cliente".
            ->when($filters['organization'] ?? null, fn (Builder $q, string $v) => $q
                ->whereHas('organization', fn (Builder $o) => $o->where('public_id', $v)))
            // Por equipo: el rastro completo de una pieza concreta.
            ->when($filters['asset'] ?? null, fn (Builder $q, string $v) => $q
                ->where('subject_type', Asset::class)
                ->whereIn('subject_id', Asset::withoutGlobalScopes()->where('public_id', $v)->select('id')))
            ->when($filters['date_from'] ?? null, fn (Builder $q, string $v) => $q->whereDate('created_at', '>=', $v))
            ->when($filters['date_to'] ?? null, fn (Builder $q, string $v) => $q->whereDate('created_at', '<=', $v))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(TableQuery::perPage($request, 50))
            ->withQueryString()
            ->through(fn (ActivityLog $log) => [
                'id' => $log->id,
                // El código crudo viaja igual: sirve para depurar y para que el
                // filtro siga funcionando. Lo que se muestra es `actionLabel`.
                'action' => $log->action,
                'actionLabel' => AuditActions::label($log->action),
                'actionArea' => AuditActions::area($log->action),
                'actionTone' => AuditActions::tone($log->action),
                'description' => $log->description,
                'user' => $log->user?->name,
                'userEmail' => $log->user?->email,
                'organization' => $log->organization?->name,
                'subjectType' => $log->subject_type ? class_basename($log->subject_type) : null,
                // Sobre qué equipo se actuó, con enlace a su ficha: es lo que
                // permite pasar de la auditoría al hilo de la pieza sin buscar.
                'asset' => $log->subject instanceof Asset ? [
                    'id' => $log->subject->public_id,
                    // Se usan las columnas propias y no displayName(), que cae
                    // al catálogo y dispararía otra consulta por fila.
                    'name' => $log->subject->description
                        ?: ($log->subject->workshop_description ?: 'Pieza'),
                    'serial' => $log->subject->serial_number ?? $log->subject->code,
                ] : null,
                'ipAddress' => $log->ip_address,
                'createdAt' => $log->created_at?->toIso8601String(),
                'changes' => $log->changes,
            ]);

        return Inertia::render('admin/audit', [
            'logs' => $logs,
            'filters' => $filters,
            /*
             * El filtro por empresa sólo tiene sentido para quien ve más de
             * una: a un cliente le sobraría, porque todo lo que ve ya es suyo.
             */
            'organizationOptions' => $actor->isClientUser() ? [] : Organization::query()
                ->orderBy('name')
                ->get(['public_id', 'name'])
                ->map(fn (Organization $o) => ['value' => $o->public_id, 'label' => $o->name])
                ->all(),
            'actionOptions' => AuditActions::options(
                ActivityLog::query()
                    ->when($actor->isClientUser(), fn (Builder $q) => $q->where('organization_id', $actor->organization_id))
                    ->select('action')->distinct()->pluck('action')
            ),
        ]);
    }
}
