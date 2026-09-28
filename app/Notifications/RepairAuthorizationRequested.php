<?php

namespace App\Notifications;

use App\Models\Reception;
use App\Notifications\Concerns\WritesDatabaseImmediately;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Aviso al cliente: hay un equipo esperando que decida si lo repara.
 *
 * Sin este aviso el circuito se traba en silencio. El taller pregunta, el
 * equipo queda inmovilizado, y el cliente sólo se entera si entra al sistema
 * por su cuenta. Como el equipo mientras tanto no se certifica ni vuelve, la
 * demora se la come el cliente sin saber que existe.
 *
 * Se encola para que un servidor de correo lento no demore la operación del
 * taller, que es lo que está haciendo la persona que aprieta el botón.
 */
class RepairAuthorizationRequested extends Notification implements ShouldQueue
{
    use Queueable, WritesDatabaseImmediately;

    public function __construct(private Reception $reception) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        // También en la aplicación: hoy la salida de correo del servidor está
        // bloqueada, y este aviso no puede depender de que se resuelva. El
        // correo de contacto de la organización no es un usuario y no tiene
        // bandeja en la aplicación: a ese sólo le llega el mail.
        return $notifiable instanceof AnonymousNotifiable ? ['mail'] : ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $asset = $this->reception->asset;
        $equipo = $asset?->displayName() ?? 'Un equipo';

        $mail = (new MailMessage)
            ->subject("Autorización de reparación requerida — {$equipo}")
            ->greeting('Un equipo suyo requiere una decisión')
            ->line(sprintf(
                'El equipo **%s**%s no pasó la inspección en el taller de Odessa Oil.',
                $equipo,
                $asset?->serial_number ? " (serie {$asset->serial_number})" : '',
            ));

        if (filled($this->reception->outcome_notes)) {
            $mail->line('**Motivo del rechazo:** '.$this->reception->outcome_notes);
        }

        if (filled($this->reception->repair_request_notes)) {
            $mail->line('**Reparación propuesta:** '.$this->reception->repair_request_notes);
        }

        return $mail
            ->line('Hasta que nos indique cómo seguir, el equipo permanece en el taller sin avanzar.')
            // Directo a esta recepción. Si no hay sesión, el login devuelve
            // acá: la respuesta queda cargada por el propio cliente.
            ->action('Responder en el sistema', url($this->reception->url()))
            ->line('Si no autoriza la reparación, le devolvemos el equipo tal como está. '.
                'En ese caso queda **no apto** y no puede volver a servicio hasta recertificarlo.');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'repair_authorization_requested',
            'reception_id' => $this->reception->public_id,
            'asset_id' => $this->reception->asset?->public_id,
            'asset_name' => $this->reception->asset?->displayName(),
            'serial_number' => $this->reception->asset?->serial_number,
            'proposal' => $this->reception->repair_request_notes,
            'url' => $this->reception->url(),
        ];
    }
}
