<?php

namespace App\Console\Commands;

use App\Models\AccountRequest;
use App\Models\User;
use App\Notifications\PendingAccountRequestsDigest;
use App\Support\Permissions;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

/**
 * Resumen de solicitudes de acceso pendientes.
 *
 * El negocio no quiere un correo por cada solicitud: prefiere un par de avisos
 * al día con todo lo que queda por aprobar, para tener el control. La campana
 * dentro de la aplicación es aparte y sí es inmediata. Si no hay nada
 * pendiente, no se manda nada: no se avisa de una casilla vacía.
 */
class SendPendingAccountRequestsDigest extends Command
{
    protected $signature = 'odessa:resumen-solicitudes
                            {--dry-run : Muestra cuántas pendientes hay, sin enviar nada}';

    protected $description = 'Avisa a los superadministradores qué solicitudes de acceso quedan por aprobar';

    public function handle(): int
    {
        $pendientes = AccountRequest::query()
            ->where('status', AccountRequest::STATUS_PENDING)
            ->orderBy('created_at')
            ->get();

        if ($pendientes->isEmpty()) {
            $this->info('No hay solicitudes de acceso pendientes.');

            return self::SUCCESS;
        }

        $admins = User::query()
            ->where('is_active', true)
            ->whereNull('organization_id')
            ->whereHas('roles', fn ($q) => $q->where('name', Permissions::ROLE_SUPER_ADMIN))
            ->get();

        if ($admins->isEmpty()) {
            $this->warn('Hay solicitudes pendientes, pero no hay superadministradores activos a quienes avisar.');

            return self::SUCCESS;
        }

        $resumen = $pendientes->map(fn (AccountRequest $solicitud) => [
            'name' => $solicitud->name,
            'company' => $solicitud->company,
            'email' => $solicitud->email,
            'since' => $solicitud->created_at->translatedFormat('d/m/Y'),
        ]);

        $this->line(sprintf(
            '%d solicitud(es) pendiente(s) -> %s',
            $pendientes->count(),
            $admins->pluck('email')->implode(', '),
        ));

        if ($this->option('dry-run')) {
            $this->info('Simulación terminada. No se envió nada.');

            return self::SUCCESS;
        }

        Notification::send($admins, new PendingAccountRequestsDigest($resumen));

        $this->info("Resumen enviado a {$admins->count()} superadministrador(es).");

        return self::SUCCESS;
    }
}
