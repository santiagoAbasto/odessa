<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

/**
 * Resumen semanal para Odessa: todo lo que vence, empresa por empresa.
 *
 * Es la contracara del aviso al cliente. El cliente recibe sólo lo suyo; el
 * superadministrador necesita la foto completa para anticipar la carga del
 * taller y saber a quién hay que apurar.
 *
 * Semanal y en una sola pieza: un correo por vencimiento sería ruido y se
 * dejaría de leer a la semana.
 */
class RecertificationDigest extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  Collection<string, array{due: int, missed: int, assets: Collection}>  $byOrganization
     */
    public function __construct(private Collection $byOrganization) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $totalDue = $this->byOrganization->sum('due');
        $totalMissed = $this->byOrganization->sum('missed');

        $mail = (new MailMessage)
            ->subject(sprintf(
                'Resumen semanal de recertificaciones — %d por despachar, %d fuera de plazo',
                $totalDue,
                $totalMissed,
            ))
            ->greeting('Recertificaciones de la semana');

        if ($totalMissed > 0) {
            $mail->line(sprintf(
                '**%d %s fuera de plazo.** Ya no alcanzan a recertificarse antes de '.
                'su vencimiento con los tiempos habituales: o se tratan como urgentes '.
                'o el cliente pierde la certificación.',
                $totalMissed,
                $totalMissed === 1 ? 'equipo está' : 'equipos están',
            ));
        }

        foreach ($this->byOrganization as $organization => $data) {
            $mail->line(sprintf(
                '**%s** — %d por despachar, %d fuera de plazo',
                $organization,
                $data['due'],
                $data['missed'],
            ));

            foreach ($data['assets'] as $asset) {
                $mail->line(sprintf(
                    '　· %s (%s) — vence %s · límite de envío %s',
                    $asset->displayName(),
                    $asset->serial_number ?? $asset->code ?? 's/id',
                    $asset->expires_at?->format('d/m/Y') ?? '—',
                    $asset->send_by_at?->format('d/m/Y') ?? '—',
                ));
            }
        }

        if ($this->byOrganization->isEmpty()) {
            $mail->line('No hay equipos que requieran atención esta semana.');
        }

        return $mail
            ->action('Abrir el panel', url('/panel'))
            ->salutation('— '.config('app.name'));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'recertification_digest',
            'title' => 'Resumen semanal de recertificaciones',
            'body' => sprintf(
                '%d por despachar · %d fuera de plazo · %d empresas',
                $this->byOrganization->sum('due'),
                $this->byOrganization->sum('missed'),
                $this->byOrganization->count(),
            ),
            'url' => '/panel',
        ];
    }
}
