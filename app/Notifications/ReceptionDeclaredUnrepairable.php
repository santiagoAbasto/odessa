<?php

namespace App\Notifications;

use App\Models\Reception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Aviso al cliente: el taller determinó que un equipo suyo no tiene solución.
 *
 * El equipo vuelve sin certificar sin que el cliente lo haya decidido, así
 * que se le avisa en el momento y no cuando lo recibe: tiene que planificar
 * un reemplazo, y cuanto antes lo sepa mejor.
 */
class ReceptionDeclaredUnrepairable extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private Reception $reception) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return $notifiable instanceof AnonymousNotifiable ? ['mail'] : ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $asset = $this->reception->asset;
        $equipo = $asset?->displayName() ?? 'Un equipo';

        return (new MailMessage)
            ->subject("Equipo sin reparación posible — {$equipo}")
            ->greeting('Un equipo suyo no tiene reparación posible')
            ->line(sprintf(
                'El taller de Odessa Oil determinó que el equipo **%s**%s no tiene solución.',
                $equipo,
                $asset?->serial_number ? " (serie {$asset->serial_number})" : '',
            ))
            ->line('**Motivo:** '.$this->reception->unrepairable_notes)
            ->line('Se le devuelve tal como está. Queda **no apto** y no puede volver a servicio sin recertificarse.')
            ->action('Ver en el sistema', url($this->reception->url()));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'reception_unrepairable',
            'reception_id' => $this->reception->public_id,
            'asset_id' => $this->reception->asset?->public_id,
            'asset_name' => $this->reception->asset?->displayName(),
            'serial_number' => $this->reception->asset?->serial_number,
            'reason' => $this->reception->unrepairable_notes,
            'url' => $this->reception->url(),
        ];
    }
}
