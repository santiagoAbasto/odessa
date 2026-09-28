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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Gestión de usuarios.
 *
 * Un administrador de cliente sólo ve y gestiona usuarios de su empresa: el
 * listado se acota por `organization_id` y cada acción vuelve a pasar por la
 * Policy. La contraseña inicial, y la de quien pierde el acceso, la genera el
 * sistema: el administrador se la pasa al usuario y éste la reemplaza al
 * entrar. No depende de que el servidor pueda mandar correos.
 */
class UserController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', User::class);

        $actor = $request->user();
        $filters = TableQuery::filters($request, ['search', 'role', 'status', 'organization']);

        $users = User::query()
            ->with(['organization:id,public_id,name', 'permissions:id,name'])
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
                // Sólo los de la lista para clientes: son los que se editan acá.
                'extraPermissions' => $u->permissions->pluck('name')
                    ->intersect(array_keys(Permissions::clientGrantable()))->values()->all(),
                'lastLoginAt' => $u->last_login_at?->toIso8601String(),
                'canEdit' => $actor->can('update', $u),
                'canDelete' => $actor->can('delete', $u),
                'canResetAccess' => $actor->can('update', $u) && ! $u->is($actor),
            ]);

        return Inertia::render('admin/users/index', [
            'users' => $users,
            'filters' => $filters,
            'roleOptions' => $this->roleOptions($actor),
            /*
             * Se arma a mano y no con `public_id as id`: Eloquent castea `id`
             * a entero, y un UUIDv7 que empieza con «01a0…» quedaba en 1 para
             * todas las organizaciones. El alta asignaba así cualquier usuario
             * cliente a la organización 1.
             */
            'organizations' => $actor->isStaff()
                ? Organization::query()->orderBy('name')->get(['public_id', 'name'])
                    ->map(fn (Organization $o) => ['id' => $o->public_id, 'name' => $o->name])
                    ->all()
                : [],
            'grantablePermissions' => collect(Permissions::clientGrantable())
                ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
                ->values()->all(),
            // Lo que cada rol de cliente ya incluye de esa lista: se muestra
            // tildado y bloqueado, porque sumarlo no cambiaría nada.
            'roleGrants' => collect(Permissions::clientRoles())
                ->mapWithKeys(fn (string $role) => [$role => array_values(array_intersect(
                    Permissions::roleMatrix()[$role],
                    array_keys(Permissions::clientGrantable()),
                ))])->all(),
            // Una sola vez, justo después de generarla: no se guarda en claro.
            'temporaryAccess' => $request->session()->get('temporaryAccess'),
            'can' => [
                'create' => $actor->can('create', User::class),
                'grantPermissions' => $actor->can('manageRoles', User::class),
            ],
        ]);
    }

    public function store(StoreUserRequest $request): RedirectResponse
    {
        $this->authorize('create', User::class);

        $actor = $request->user();
        $data = $request->validated();

        $organizationId = $actor->isStaff()
            ? Organization::query()->where('public_id', $data['organization_id'] ?? null)->value('id')
            : $actor->organization_id; // un cliente nunca elige otra organización

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'job_title' => $data['job_title'] ?? null,
            'organization_id' => $organizationId,
            'is_active' => true,
            'password' => Hash::make($temporal = $this->temporaryPassword()),
            'must_change_password' => true,
        ]);

        $user->syncRoles($this->allowedRoles($actor, $data['roles']));
        $this->syncExtraPermissions($actor, $user, $data['extra_permissions'] ?? []);

        ActivityLogger::log(
            action: 'user.created',
            subject: $user,
            description: "Alta de usuario {$user->email}",
            new: ['name' => $user->name, 'email' => $user->email, 'roles' => $data['roles']],
            organizationId: $organizationId,
        );

        return back()->with('temporaryAccess', $this->temporaryAccess($user, $temporal, created: true));
    }

    /**
     * Para quien perdió el acceso: una contraseña temporal nueva.
     *
     * La anterior deja de funcionar y se cierran las sesiones abiertas del
     * usuario, por si el motivo es que alguien más la conocía.
     */
    public function resetAccess(Request $request, User $user): RedirectResponse
    {
        // Con la propia se cerraría la sesión antes de ver la clave nueva.
        abort_if($request->user()->is($user), 403, 'No puede restablecer su propio acceso.');

        $this->authorize('update', $user);

        $user->forceFill([
            'password' => Hash::make($temporal = $this->temporaryPassword()),
            'must_change_password' => true,
            'remember_token' => Str::random(60),
        ])->save();

        // ponytail: sólo corta sesiones con SESSION_DRIVER=database, el de producción.
        DB::table('sessions')->where('user_id', $user->getKey())->delete();

        ActivityLogger::log(
            action: 'user.access_reset',
            subject: $user,
            description: "Acceso restablecido de {$user->email}",
            organizationId: $user->organization_id,
        );

        return back()->with('temporaryAccess', $this->temporaryAccess($user, $temporal, created: false));
    }

    /** Sin símbolos: se dicta o se pega en un mensaje sin confundirse. */
    private function temporaryPassword(): string
    {
        return Str::password(12, symbols: false);
    }

    /** @return array{name: string, email: string, password: string, created: bool} */
    private function temporaryAccess(User $user, string $password, bool $created): array
    {
        return ['name' => $user->name, 'email' => $user->email, 'password' => $password, 'created' => $created];
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

        if (array_key_exists('extra_permissions', $data)) {
            $this->syncExtraPermissions($actor, $user, $data['extra_permissions']);
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
     * Permisos adicionales de un usuario cliente, por encima de su rol.
     *
     * Sólo los administra quien administra roles, y sólo dentro de la lista
     * para clientes: cualquier otro permiso directo que el usuario tuviera no
     * lo toca esta pantalla. Al personal interno no se le guardan: su acceso
     * lo define el rol.
     *
     * @param  list<string>  $requested
     */
    private function syncExtraPermissions(User $actor, User $user, array $requested): void
    {
        if (! $actor->can('manageRoles', User::class)) {
            return;
        }

        $grantable = array_keys(Permissions::clientGrantable());
        $before = $user->getDirectPermissions()->pluck('name')->all();
        $pedidos = $user->isClientUser() ? array_values(array_intersect($requested, $grantable)) : [];

        $after = [...array_values(array_diff($before, $grantable)), ...$pedidos];

        if (collect($before)->sort()->values()->all() === collect($after)->sort()->values()->all()) {
            return;
        }

        $user->syncPermissions($after);

        ActivityLogger::log(
            action: 'user.permissions_updated',
            subject: $user,
            description: "Permisos adicionales de {$user->email}",
            old: ['permissions' => $before],
            new: ['permissions' => $after],
            organizationId: $user->organization_id,
        );
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
