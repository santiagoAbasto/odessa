<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Support\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Reemplazo obligatorio de la contraseña temporal.
 *
 * Sólo existe para quien tiene una temporal. Sin eso, cualquier sesión
 * abierta —también una ajena— podría cambiar la clave sin conocerla.
 */
class PasswordChangeController extends Controller
{
    public function edit(Request $request): Response|RedirectResponse
    {
        if (! $request->user()->must_change_password) {
            return redirect()->route('dashboard');
        }

        return Inertia::render('auth/change-password', [
            'email' => $request->user()->email,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();

        if (! $user->must_change_password) {
            return redirect()->route('dashboard');
        }

        $request->validate([
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ], [
            'password.confirmed' => 'La confirmación no coincide con la nueva contraseña.',
        ]);

        if (Hash::check($request->string('password')->value(), $user->password)) {
            throw ValidationException::withMessages([
                'password' => 'Tiene que ser distinta de la contraseña temporal.',
            ]);
        }

        $user->forceFill([
            'password' => Hash::make($request->string('password')->value()),
            'must_change_password' => false,
        ])->save();

        // Si entró con la temporal, el enlace del correo de bienvenida queda
        // vivo en su casilla una semana. Ya eligió contraseña: se anula, para
        // que nadie con acceso a ese correo pueda volver a cambiarla.
        $user->revokeEmailLinks();

        ActivityLogger::log('auth.password_changed', $user, 'Contraseña temporal reemplazada');

        return redirect()->route('dashboard');
    }
}
