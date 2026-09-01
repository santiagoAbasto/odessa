<?php

namespace App\Notifications;

use App\Models\AccountRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Avisa de una nueva solicitud de acceso.
 *
 * Va por correo y además queda como alerta dentro de la aplicación, para que
 * el administrador no tenga que entrar a comprobar si hay solicitudes nuevas.
 *
 * Se encola: el visitante no debe esperar a que el servidor de correo responda,
 * ni ver un error si el correo falla.
 */
class AccountRequestReceived extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private AccountRequest $accountRequest) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $request = $this->accountRequest;

        return (new MailMessage)
            ->subject("Nueva solicitud de acceso: {$request->company}")
            ->greeting('Nueva solicitud de acceso')
            ->line("**{$request->name}**, de **{$request->company}**, solicitó acceso al portal.")
            ->line("Correo de contacto: {$request->email}")
            ->when($request->phone, fn (MailMessage $mail) => $mail->line("Teléfono: {$request->phone}"))
            ->when($request->message, fn (MailMessage $mail) => $mail->line("Comentario: «{$request->message}»"))
            ->action('Revisar la solicitud', url('/administracion/solicitudes'))
            ->line('La cuenta no se crea sola: hay que revisarla y darla de alta desde Usuarios.')
            ->salutation('— '.config('app.name'));
    }

    /** Datos de la alerta dentro de la aplicación. */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'account_request',
            'title' => 'Nueva solicitud de acceso',
            'body' => "{$this->accountRequest->name} · {$this->accountRequest->company}",
            'url' => '/administracion/solicitudes',
            'requestId' => $this->accountRequest->public_id,
        ];
    }
}
