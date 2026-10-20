<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Bienvenida de un usuario recién creado, con un enlace para que elija su
 * contraseña.
 *
 * Nunca lleva la contraseña temporal: una clave escrita en un correo queda al
 * alcance de cualquiera que entre a esa casilla, hoy o dentro de un año. El
 * enlace, en cambio, vence y sirve una sola vez. La temporal sigue existiendo
 * para el otro camino, el que el administrador manda por WhatsApp.
 *
 * El enlace se arma en el momento del alta, dentro de la petición, y llega ya
 * hecho: así no depende de con qué dirección arranque el proceso que vacía la
 * cola. Va encolado como todos los correos, para que el administrador no espere
 * al servidor de correo y para que una falla de envío no tire abajo el alta.
 */
class WelcomeUser extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $url) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $days = intdiv((int) config('auth.passwords.invitations.expire'), 60 * 24);

        return (new MailMessage)
            ->subject('Su acceso a '.config('app.name'))
            ->greeting("Hola, {$notifiable->name}")
            ->line('Se creó su usuario en el portal de '.config('app.name').'.')
            ->line('Para empezar, elija su contraseña desde este enlace:')
            ->action('Elegir mi contraseña', $this->url)
            ->line("Su usuario es **{$notifiable->email}**.")
            ->line("El enlace vence en {$days} días y sirve una sola vez. Si se le venció, "
                .'pida uno nuevo con «¿Olvidó su contraseña?» en la pantalla de ingreso.')
            ->line('Si no esperaba este correo, puede ignorarlo.')
            ->salutation('— '.config('app.name'));
    }
}
