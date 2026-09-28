<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Models\Organization;
use App\Models\User;
use App\Support\ActivityLogger;
use App\Support\Permissions;
use App\Support\TableQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Gestión de usuarios.
 *
 * Un administrador de cliente sólo ve y gestiona usuarios de su empresa: el
 * listado se acota por `organization_id` y cada acción vuelve a pasar por la
 * Policy. La contraseña inicial nunca se elige aquí: se envía un enlace de
 * establecimiento al correo del usuario.
 */
class UserController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', User::class);

        $actor = $request->user();
        $filters = TableQuery::filters($request, ['search', 'role', 'status', 'organization']);

        $users = User::query()
            ->with('organization:id,public_id,name')
            ->when($actor->isClientUser(), fn (Builder $q) => $q->where('organization_id', $actor->organization_id))
            ->when($filters['search'] ?? null, fn (Builder $q, string $v) => $q
                ->where(fn (Builder $i) => $i
                    ->where('name', 'like', TableQuery::like($v))
                    ->orWhere('email', 'like', TableQuery::like($v))))
            ->when($filters['role'] ?? null, fn (Builder $q, string $v) => $q->role($v))
            ->when(($filters['status'] ?? null) === 'active', fn (Builder $q) => $q->where('is_active', true))
            ->when(($filters['status'] ?? null) === 'inactive', fn (Builder $q) => $q->where('is_active', false))
            ->when($filters['organization'] ?? null, fn (Builder $q, string $v) => $q
                ->whereHas('organization', fn (Builder $o) => $o->where('public_id', $v)))
            ->orderBy('name')
            ->paginate(TableQuery::perPage($request))
            ->withQueryString()
            ->through(fn (User $u) => [
                'id' => $u->public_id,
                'name' => $u->name,
                'email' => $u->email,
                'jobTitle' => $u->job_title,
                'phone' => $u->phone,
                'isActive' => $u->is_active,
                'isStaff' => $u->isStaff(),
                'organization' => $u->organization?->name,
                'roles' => $u->getRoleNames()->map(fn ($r) => Permissions::roleLabels()[$r] ?? $r)->all(),
                // Los nombres crudos, para preseleccionar al editar. Las
                // etiquetas de arriba son para mostrar y no sirven de valor.
                'roleNames' => $u->getRoleNames()->all(),
                'lastLoginAt' => $u->last_login_at?->toIso8601String(),
                'canEdit' => $actor->can('update', $u),
                'canDelete' => $actor->can('delete', $u),
            ]);

        return Inertia::render('admin/users/index', [
            'users' => $users,
            'filters' => $filters,
            'roleOptions' => $this->roleOptions($actor),
            'organizations' => $actor->isStaff()
                ? Organization::query()->orderBy('name')->get(['public_id as id', 'name'])
                : [],
            'can' => ['create' => $actor->can('create', User::class)],
        ]);
    }

    public function store(StoreUserRequest $request): RedirectResponse
    {
        $this->authorize('create', User::class);

        $actor = $request->user();
        $data = $request->validated();

        $organizationId = $actor->isStaff()
            ? ($data['organization_id'] ?? null)
            : $actor->organization_id; // un cliente nunca elige otra organización

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'job_title' => $data['job_title'] ?? null,
            'organization_id' => $organizationId,
            'is_active' => true,
            // Contraseña aleatoria descartable: el usuario la define por correo.
            'password' => Hash::make(Str::random(48)),
        ]);

        $user->syncRoles($this->allowedRoles($actor, $data['roles']));

        ActivityLogger::log(
            action: 'user.created',
            subject: $user,
            description: "Alta de usuario {$user->email}",
            new: ['name' => $user->name, 'email' => $user->email, 'roles' => $data['roles']],
            organizationId: $organizationId,
        );

        // La clave aleatoria nunca se entrega: el usuario establece la suya
        // mediante el token de un solo uso, con expiración y throttle del broker.
        try {
            $resetStatus = Password::sendResetLink(['email' => $user->email]);
        } catch (\Throwable $exception) {
            report($exception);
            $resetStatus = null;
        }

        if ($resetStatus !== Password::ResetLinkSent) {
            report(new \RuntimeException("No se pudo enviar el enlace inicial a {$user->email}: ".($resetStatus ?? 'error de transporte')));

            return back()->with('warning', 'Usuario creado, pero no se pudo enviar el correo. Reintente desde recuperación de contraseña.');
        }

        return back()->with('success', "Usuario creado. Se envió un enlace a {$user->email} para definir su contraseña.");
    }

    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        $this->authorize('update', $user);

        $actor = $request->user();
        $data = $request->validated();
        $before = $user->only(['name', 'email', 'phone', 'job_title', 'is_active']);

        $user->update([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'job_title' => $data['job_title'] ?? null,
            'is_active' => $data['is_active'] ?? $user->is_active,
        ]);

        if (isset($data['roles']) && $actor->can('manageRoles', User::class)) {
            $user->syncRoles($this->allowedRoles($actor, $data['roles']));
        }

        ActivityLogger::log(
            action: 'user.updated',
            subject: $user,
            description: "Modificación de usuario {$user->email}",
            old: $before,
            new: $user->only(['name', 'email', 'phone', 'job_title', 'is_active']),
            organizationId: $user->organization_id,
        );

        return back()->with('success', 'Usuario actualizado.');
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        /**
         * Invariante, no permiso: nadie se desactiva a sí mismo.
         *
         * Va aquí y no sólo en la Policy porque el superadministrador atraviesa
         * las Policies con Gate::before; si dependiera de ellas, justamente la
         * cuenta que puede dejar el sistema sin administrador sería la única
         * capaz de saltarse la comprobación.
         */
        abort_if(
            $request->user()->getKey() === $user->getKey(),
            403,
            'No puede desactivar su propia cuenta.'
        );

        $this->authorize('delete', $user);

        // Baja lógica: se conserva la trazabilidad de lo que hizo el usuario.
        $user->update(['is_active' => false]);
        $user->delete();

        ActivityLogger::log(
            action: 'user.deactivated',
            subject: $user,
            description: "Baja de usuario {$user->email}",
            organizationId: $user->organization_id,
        );

        return back()->with('success', 'Usuario desactivado.');
    }

    /**
     * Un usuario nunca puede otorgar un rol que él mismo no posee ni un rol
     * interno de Odessa Oil si es un usuario de cliente. Evita la escalada de privilegios.
     *
     * @param  list<string>  $requested
     * @return list<string>
     */
    private function allowedRoles(User $actor, array $requested): array
    {
        $allowed = array_column($this->roleOptions($actor), 'value');

        return array_values(array_intersect($requested, $allowed));
    }

    private function roleOptions(User $actor): array
    {
        $available = $actor->isStaff()
            ? array_keys(Permissions::roleLabels())
            : Permissions::clientRoles();

        if ($actor->isStaff() && ! $actor->hasRole(Permissions::ROLE_SUPER_ADMIN)) {
            $available = array_values(array_diff($available, [Permissions::ROLE_SUPER_ADMIN]));
        }

        return array_map(
            fn (string $role) => ['value' => $role, 'label' => Permissions::roleLabels()[$role]],
            $available
        );
    }
}
