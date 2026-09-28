<?php

namespace App\Notifications;

use App\Models\Asset;
use App\Support\RecertificationWindow;
use App\Notifications\Concerns\WritesDatabaseImmediately;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

/**
 * Aviso al cliente: estos equipos hay que mandarlos ahora.
 *
 * No avisa de vencimientos: avisa de **fechas límite de envío**. Para cuando
 * la certificación está por vencer ya es tarde, porque el taller necesita su
 * tiempo. El correo dice, para cada equipo, hasta cuándo puede despacharlo.
 *
 * Va agrupado por empresa y no un correo por equipo: quien lo recibe necesita
 * una lista con la que ir a buscar los equipos al yacimiento, no veinte
 * mensajes sueltos que va a terminar ignorando.
 *
 * Se encola porque lo dispara una tarea programada y puede abarcar muchas
 * empresas; que un servidor de correo lento no bloquee el resto.
 */
class RecertificationDue extends Notification implements ShouldQueue
{
    use Queueable, WritesDatabaseImmediately;

    /**
     * @param  Collection<int, Asset>  $dueSoon   Todavía a tiempo de despachar.
     * @param  Collection<int, Asset>  $missed    Ya no llegan sin perder vigencia.
     */
    public function __construct(
        private Collection $dueSoon,
        private Collection $missed,
        private string $organizationName,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $total = $this->dueSoon->count() + $this->missed->count();

        $mail = (new MailMessage)
            ->subject(sprintf(
                '[%s] %d %s requieren recertificación',
                $this->organizationName,
                $total,
                $total === 1 ? 'equipo' : 'equipos',
            ))
            ->greeting('Equipos a enviar para recertificación');

        // Lo urgente primero: lo que ya no llega a tiempo.
        if ($this->missed->isNotEmpty()) {
            $mail->line('**Fuera de plazo.** Estos equipos ya no alcanzan a recertificarse '.
                'antes de su vencimiento con los tiempos habituales del taller. '.
                'Coordine con Odessa Oil para tratarlos como urgentes:');

            foreach ($this->missed as $asset) {
                $mail->line('· '.$this->describe($asset));
            }
        }

        if ($this->dueSoon->isNotEmpty()) {
            $mail->line('**A despachar.** Todavía llegan a tiempo si salen antes de la '.
                'fecha indicada para cada uno:');

            foreach ($this->dueSoon as $asset) {
                $mail->line('· '.$this->describe($asset));
            }
        }

        return $mail
            ->action('Ver los equipos en el portal', url('/activos'))
            ->line('La fecha de envío contempla el tiempo que el taller necesita para '.
                'trabajar el equipo más un margen para el transporte. Respetándola, '.
                'el equipo no pierde la certificación en ningún momento.')
            ->salutation('— '.config('app.name'));
    }

    /** Una línea por equipo: qué es, cuándo vence y hasta cuándo puede mandarlo. */
    private function describe(Asset $asset): string
    {
        $window = $asset->recertificationWindow();

        return sprintf(
            '**%s** (%s) — vence el %s · despachar antes del %s',
            $asset->displayName(),
            $asset->serial_number ?? $asset->code ?? 'sin identificación',
            $asset->expires_at?->format('d/m/Y') ?? '—',
            $window->sendBy()?->format('d/m/Y') ?? '—',
        );
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'recertification_due',
            'title' => 'Equipos a enviar para recertificación',
            'body' => sprintf(
                '%d por despachar · %d fuera de plazo',
                $this->dueSoon->count(),
                $this->missed->count(),
            ),
            'url' => '/activos?alerta='.RecertificationWindow::STATE_DUE,
        ];
    }
}
