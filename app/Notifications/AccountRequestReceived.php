<?php

namespace App\Notifications;

use App\Models\AccountRequest;
use App\Notifications\Concerns\WritesDatabaseImmediately;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Alerta de una nueva solicitud de acceso, dentro de la aplicación.
 *
 * Es sólo la campana, y se escribe en el momento (WritesDatabaseImmediately):
 * el administrador ve la solicitud apenas entra, sin depender de la cola ni del
 * servidor de correo.
 *
 * El aviso por correo no va aquí. Para no llenarle la casilla con un mensaje por
 * cada solicitud, el negocio pidió un resumen un par de veces al día con todo lo
 * que queda por aprobar (comando `odessa:resumen-solicitudes`).
 */
class AccountRequestReceived extends Notification implements ShouldQueue
{
    use Queueable, WritesDatabaseImmediately;

    public function __construct(private AccountRequest $accountRequest) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
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
