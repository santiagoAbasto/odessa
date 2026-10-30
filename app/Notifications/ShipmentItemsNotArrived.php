<?php

namespace App\Notifications;

use App\Models\Shipment;
use App\Notifications\Concerns\WritesDatabaseImmediately;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Aviso al operador del cliente: al dar recepción a su envío, uno o más de los
 * equipos que declaró no llegaron al taller. No se ingresaron; el resto sí.
 *
 * Se le avisa en el momento para que sepa qué quedó afuera y por qué, y pueda
 * volver a mandarlo o aclararlo.
 */
class ShipmentItemsNotArrived extends Notification implements ShouldQueue
{
    use Queueable, WritesDatabaseImmediately;

    /**
     * @param  list<array{name: string, serial: ?string, reason: string}>  $missing
     */
    public function __construct(private Shipment $shipment, private array $missing) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return $notifiable instanceof AnonymousNotifiable ? ['mail'] : ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject("Equipos que no llegaron — envío {$this->shipment->reference}")
            ->greeting('Faltaron equipos de su envío')
            ->line(sprintf(
                'Al dar recepción al envío **%s**, el taller de Odessa Oil no recibió los siguientes equipos, así que **no se ingresaron** al taller:',
                $this->shipment->reference,
            ));

        foreach ($this->missing as $item) {
            $serie = $item['serial'] ? " (serie {$item['serial']})" : '';
            $mail->line(sprintf('• **%s**%s — %s', $item['name'], $serie, $item['reason']));
        }

        return $mail
            ->line('El resto de los equipos sí ingresó y ya está en el taller.')
            ->line('Si es un error, respóndanos o vuelva a declarar esos equipos en un nuevo envío.')
            ->action('Ver el envío', url("/envios/{$this->shipment->public_id}"))
            ->salutation('Odessa Oil');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'shipment_items_not_arrived',
            'shipment_id' => $this->shipment->public_id,
            'reference' => $this->shipment->reference,
            'missing' => $this->missing,
        ];
    }
}
