<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AccountRequest;
use App\Models\Organization;
use App\Support\ActivityLogger;
use App\Support\Permissions;
use App\Support\TableQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Revisión de solicitudes de acceso.
 *
 * Aprobar una solicitud no crea el usuario automáticamente: sólo la marca como
 * aprobada y la asocia a una organización. El alta se hace desde Usuarios, de
 * forma deliberada, para que quede claro quién otorga qué rol.
 */
class AccountRequestReviewController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()->can(Permissions::ACCOUNT_REQUESTS_MANAGE), 403);

        $filters = TableQuery::filters($request, ['status', 'search']);

        $requests = AccountRequest::query()
            ->with(['organization:id,name', 'reviewer:id,name'])
            ->when($filters['status'] ?? 'pending', fn ($q, $v) => $v === 'all' ? $q : $q->where('status', $v))
            ->when($filters['search'] ?? null, fn ($q, string $v) => $q
                ->where(fn ($i) => $i
                    ->where('email', 'like', TableQuery::like($v))
                    ->orWhere('company', 'like', TableQuery::like($v))
                    ->orWhere('name', 'like', TableQuery::like($v))))
            ->orderByDesc('created_at')
            ->paginate(TableQuery::perPage($request))
            ->withQueryString()
            ->through(fn (AccountRequest $r) => [
                'id' => $r->public_id,
                'name' => $r->name,
                'email' => $r->email,
                'phone' => $r->phone,
                'company' => $r->company,
                'message' => $r->message,
                'status' => $r->status,
                'organization' => $r->organization?->name,
                'reviewedBy' => $r->reviewer?->name,
                'reviewedAt' => $r->reviewed_at?->toIso8601String(),
                'createdAt' => $r->created_at?->toIso8601String(),
            ]);

        return Inertia::render('admin/account-requests', [
            'requests' => $requests,
            'filters' => $filters + ['status' => $filters['status'] ?? 'pending'],
            'organizations' => Organization::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function update(Request $request, AccountRequest $accountRequest): RedirectResponse
    {
        abort_unless($request->user()->can(Permissions::ACCOUNT_REQUESTS_MANAGE), 403);

        $data = $request->validate([
            'status' => ['required', Rule::in([AccountRequest::STATUS_APPROVED, AccountRequest::STATUS_REJECTED])],
            'organization_id' => ['nullable', 'integer', Rule::exists('organizations', 'id')],
            'review_notes' => ['nullable', 'string', 'max:500'],
        ], [
            'status.required' => 'Indique si la solicitud se aprueba o se rechaza.',
        ]);

        if ($data['status'] === AccountRequest::STATUS_APPROVED && empty($data['organization_id'])) {
            return back()->withErrors(['organization_id' => 'Seleccione la organización a la que pertenece el solicitante.']);
        }

        $previous = $accountRequest->status;

        $accountRequest->forceFill([
            'status' => $data['status'],
            'organization_id' => $data['organization_id'] ?? null,
            'review_notes' => $data['review_notes'] ?? null,
            'reviewed_by' => $request->user()->getKey(),
            'reviewed_at' => now(),
        ])->save();

        ActivityLogger::log(
            action: 'account_request.reviewed',
            subject: $accountRequest,
            description: "Solicitud de {$accountRequest->email} marcada como {$data['status']}",
            old: ['status' => $previous],
            new: ['status' => $data['status']],
            organizationId: $data['organization_id'] ?? null,
        );

        return back()->with('success', 'Solicitud actualizada.');
    }
}
