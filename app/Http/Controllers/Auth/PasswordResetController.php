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
 * Elegir una contraseña desde un enlace por correo.
 *
 * Sirve a dos casos: el "olvidé mi contraseña" y la bienvenida de un usuario
 * recién creado. Comparten formulario y validación; los separa sólo cuánto
 * dura el enlace (ver config/auth.php).
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
        return $this->form($request, $token, welcome: false);
    }

    /** El mismo formulario, entrando desde el correo de bienvenida. */
    public function welcome(Request $request, string $token): Response
    {
        return $this->form($request, $token, welcome: true);
    }

    public function update(Request $request): RedirectResponse
    {
        return $this->choosePassword(
            $request,
            broker: 'users',
            action: 'auth.password_reset',
            done: 'Su contraseña fue actualizada. Ya puede iniciar sesión.',
        );
    }

    public function storeWelcome(Request $request): RedirectResponse
    {
        return $this->choosePassword(
            $request,
            broker: 'invitations',
            action: 'auth.welcome_accepted',
            done: 'Listo, su cuenta quedó activa. Ya puede ingresar con su contraseña.',
        );
    }

    private function form(Request $request, string $token, bool $welcome): Response
    {
        return Inertia::render('auth/reset-password', [
            'token' => $token,
            'email' => $request->string('email')->value(),
            'welcome' => $welcome,
        ]);
    }

    /**
     * Valida el enlace contra su propio broker y guarda la contraseña.
     *
     * Cada broker tiene su tabla, así que un enlace sólo vale en la ruta del
     * tipo que lo creó: uno de recuperación no existe para la bienvenida, y no
     * se le puede estirar la hora de vida mandándolo a la otra ruta.
     *
     * Al terminar se anulan TODOS los enlaces pendientes del usuario, no sólo
     * el que usó: con la contraseña ya elegida, cualquier otro enlace sin usar
     * en su casilla es sólo una forma más de cambiársela.
     */
    private function choosePassword(Request $request, string $broker, string $action, string $done): RedirectResponse
    {
        $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email:filter'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ], [
            'password.confirmed' => 'La confirmación no coincide con la nueva contraseña.',
        ]);

        $status = Password::broker($broker)->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user) use ($request, $action) {
                $user->forceFill([
                    'password' => Hash::make($request->string('password')->value()),
                    // La eligió él: si tenía una temporal, ya no hace falta cambiarla.
                    'must_change_password' => false,
                    'remember_token' => Str::random(60),
                ])->save();

                $user->revokeEmailLinks();

                ActivityLogger::log($action, $user, 'Contraseña elegida desde un enlace por correo');

                event(new PasswordReset($user));
            }
        );

        if ($status !== Password::PasswordReset) {
            throw ValidationException::withMessages([
                'email' => 'El enlace no es válido o ya venció. Puede pedir uno nuevo con «¿Olvidó su contraseña?».',
            ]);
        }

        return redirect()->route('login')->with('status', $done);
    }
}
