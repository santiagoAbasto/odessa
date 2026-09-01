<?php

namespace App\Http\Middleware;

use App\Support\Permissions;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    /**
     * Datos compartidos con todas las páginas.
     *
     * Se envía la lista de permisos efectivos para que la navegación y los
     * botones se rendericen según el rol. Es sólo presentación: cada ruta y
     * cada acción vuelve a autorizar en el backend.
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),

            'auth' => [
                'user' => $user ? [
                    'id' => $user->public_id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'jobTitle' => $user->job_title,
                    'initials' => $this->initials($user->name),
                    'isStaff' => $user->isStaff(),
                    'roles' => $user->getRoleNames()->all(),
                    'roleLabels' => array_values(array_intersect_key(
                        Permissions::roleLabels(),
                        array_flip($user->getRoleNames()->all())
                    )),
                    'organization' => $user->organization ? [
                        'id' => $user->organization->public_id,
                        'name' => $user->organization->name,
                        'shortCode' => $user->organization->short_code,
                    ] : null,
                ] : null,
                'permissions' => $user
                    ? $user->getAllPermissions()->pluck('name')->values()->all()
                    : [],
            ],

            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'warning' => fn () => $request->session()->get('warning'),
                'info' => fn () => $request->session()->get('info'),
            ],

            'app' => [
                'name' => config('app.name'),
            ],
        ];
    }

    private function initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $letters = array_map(fn ($p) => mb_strtoupper(mb_substr($p, 0, 1)), array_slice($parts, 0, 2));

        return implode('', $letters);
    }
}
