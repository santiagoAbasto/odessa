<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

/**
 * Resumen de solicitudes de acceso pendientes de aprobar.
 *
 * Reemplaza al correo por cada solicitud: el administrador recibe, un par de
 * veces al día, una sola pieza con todo lo que falta resolver, así tiene el
 * control sin que se le llene la casilla. La campana dentro de la aplicación
 * sigue siendo inmediata y es aparte.
 *
 * Va encolado como el resto de los correos del sistema: lo despacha la tarea
 * programada cuando vacía la cola.
 */
class PendingAccountRequestsDigest extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param  Collection<int, array{name: string, company: string, email: string, since: string}>  $solicitudes */
    public function __construct(private Collection $solicitudes) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $total = $this->solicitudes->count();

        $mensaje = (new MailMessage)
            ->subject($total === 1
                ? 'Tenés 1 solicitud de acceso pendiente'
                : "Tenés {$total} solicitudes de acceso pendientes")
            ->greeting('Solicitudes de acceso pendientes')
            ->line($total === 1
                ? 'Hay 1 solicitud esperando que la revises:'
                : "Hay {$total} solicitudes esperando que las revises:");

        foreach ($this->solicitudes as $solicitud) {
            $mensaje->line(
                "• **{$solicitud['name']}** — {$solicitud['company']} — {$solicitud['email']} ({$solicitud['since']})"
            );
        }

        return $mensaje
            ->action('Revisar las solicitudes', url('/administracion/solicitudes'))
            ->line('La cuenta no se crea sola: hay que revisarla y darla de alta desde Usuarios.')
            ->salutation('— '.config('app.name'));
    }
}
