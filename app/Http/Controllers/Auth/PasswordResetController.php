<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Support\ActivityLogger;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Recuperación de contraseña.
 *
 * La respuesta al pedir el enlace es siempre la misma exista o no el correo:
 * el formulario no debe servir para enumerar usuarios válidos.
 */
class PasswordResetController extends Controller
{
    public function request(): Response
    {
        return Inertia::render('auth/forgot-password', [
            'status' => session('status'),
        ]);
    }

    public function email(Request $request): RedirectResponse
    {
        $request->validate(
            ['email' => ['required', 'email:filter', 'max:255']],
            ['email.required' => 'Ingrese su correo electrónico.']
        );

        Password::sendResetLink($request->only('email'));

        return back()->with('status', 'Si el correo está registrado, recibirá un enlace para restablecer su contraseña.');
    }

    public function reset(Request $request, string $token): Response
    {
        return Inertia::render('auth/reset-password', [
            'token' => $token,
            'email' => $request->string('email')->value(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email:filter'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ], [
            'password.confirmed' => 'La confirmación no coincide con la nueva contraseña.',
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user) use ($request) {
                $user->forceFill([
                    'password' => Hash::make($request->string('password')->value()),
                    // La eligió él: si tenía una temporal, ya no hace falta cambiarla.
                    'must_change_password' => false,
                    'remember_token' => Str::random(60),
                ])->save();

                ActivityLogger::log('auth.password_reset', $user, 'Contraseña restablecida');

                event(new PasswordReset($user));
            }
        );

        if ($status !== Password::PasswordReset) {
            throw ValidationException::withMessages([
                'email' => 'El enlace de restablecimiento no es válido o ya expiró.',
            ]);
        }

        return redirect()->route('login')->with('status', 'Su contraseña fue actualizada. Ya puede iniciar sesión.');
    }
}
