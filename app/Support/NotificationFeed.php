<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Lo que muestra la campana del encabezado.
 *
 * Los avisos que se guardan en la aplicación son la única vía que hoy le
 * llega a un cliente: la salida de correo del servidor está bloqueada. Los
 * de recepciones no guardan título propio —y ya hay de esos en producción—,
 * así que el título se resuelve acá por tipo, en un solo lugar.
 */
final class NotificationFeed
{
    /** Cuántos se muestran en el desplegable. El contador cuenta todos. */
    private const LIMIT = 8;

    /** @return array{unread: int, items: list<array<string, mixed>>} */
    public static function for(User $user): array
    {
        return [
            'unread' => $user->unreadNotifications()->count(),
            'items' => $user->unreadNotifications()
                ->latest()
                ->limit(self::LIMIT)
                ->get()
                ->map(fn (DatabaseNotification $n) => self::present($n))
                ->all(),
        ];
    }

    /** @return array<string, mixed> */
    private static function present(DatabaseNotification $notification): array
    {
        $data = $notification->data;

        $equipo = trim(($data['asset_name'] ?? '').(filled($data['serial_number'] ?? null) ? " · serie {$data['serial_number']}" : ''));

        return [
            'id' => $notification->id,
            'title' => $data['title'] ?? match ($data['type'] ?? null) {
                'repair_authorization_requested' => 'Autorización de reparación requerida',
                'reception_unrepairable' => 'Equipo sin reparación posible',
                default => 'Aviso',
            },
            'body' => $data['body'] ?? ($equipo !== '' ? $equipo : null),
            'at' => $notification->created_at?->toIso8601String(),
        ];
    }

    /**
     * Adónde lleva un aviso. Sólo rutas internas: el enlace sale de la base y
     * un valor como «//otro-sitio» no puede convertirse en una redirección
     * fuera del sistema.
     */
    public static function target(DatabaseNotification $notification): string
    {
        $url = (string) ($notification->data['url'] ?? '');

        return str_starts_with($url, '/') && ! str_starts_with($url, '//') && ! str_contains($url, '\\')
            ? $url
            : '/panel';
    }
}
