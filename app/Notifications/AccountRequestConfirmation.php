<?php

namespace App\Notifications;

use App\Models\AccountRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Constancia para quien pide acceso desde la parte pública.
 *
 * El texto es siempre el mismo, tenga o no cuenta ese correo: el formulario
 * no debe servir para averiguar qué direcciones están registradas. Tampoco
 * lleva enlaces, así nadie puede usar el formulario para hacer llegar a un
 * tercero un correo del sistema con un botón para tocar.
 */
class AccountRequestConfirmation extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private AccountRequest $accountRequest) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Recibimos su solicitud de acceso a '.config('app.name'))
            ->greeting("Hola, {$this->accountRequest->name}")
            ->line("Recibimos su solicitud de acceso para {$this->accountRequest->company}.")
            ->line('Un administrador de Odessa Oil la va a revisar y le va a escribir a este correo con los pasos para ingresar.')
            ->line('Si usted no hizo esta solicitud, puede ignorar este mensaje.')
            ->salutation('— '.config('app.name'));
    }
}
