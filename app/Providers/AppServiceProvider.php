<?php

namespace App\Providers;

use App\Models\User;
use App\Support\Permissions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Un nonce distinto por respuesta permite scripts inline deliberados
        // sin abrir script-src con unsafe-inline en producción.
        Vite::useCspNonce();

        /**
         * Modo estricto en desarrollo: rompe ante consultas N+1, acceso a
         * atributos no cargados y asignación masiva silenciosa. Es la forma
         * más barata de que estos errores no lleguen a producción.
         */
        Model::shouldBeStrict($this->app->isLocal());
        Model::unguard(false);

        /**
         * Política de contraseñas en todo el sistema.
         *
         * `uncompromised()` consulta la API de HaveIBeenPwned en cada
         * validación. Fuera de producción se omite: una prueba no debe
         * depender de un servicio externo — si la red falla o tarda, la
         * suite se cuelga en lugar de fallar con un mensaje claro.
         */
        Password::defaults(function () {
            $rule = Password::min(12)->letters()->mixedCase()->numbers();

            return $this->app->isProduction() ? $rule->uncompromised() : $rule;
        });

        // El superadministrador no depende de la asignación de permisos.
        Gate::before(fn (User $user, string $ability) => $user->hasRole(Permissions::ROLE_SUPER_ADMIN) ? true : null);

        /**
         * Copia interna de todo correo del sistema. Cuando hay una casilla
         * configurada, se le agrega como copia oculta a cada mensaje que sale
         * —incluidos los del framework, como la recuperación de contraseña—,
         * para no exponer la dirección al destinatario. Se hace sobre el evento
         * de envío porque el Mailer no tiene un «always bcc» propio; el chequeo
         * va adentro para respetar la configuración vigente en cada envío.
         */
        Event::listen(function (MessageSending $event): void {
            if (filled($copia = config('odessa.mail_copy_to'))) {
                $event->message->addBcc($copia);
            }
        });

        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }
    }
}
