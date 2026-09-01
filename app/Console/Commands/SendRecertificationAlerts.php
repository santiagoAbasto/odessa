<?php

namespace App\Console\Commands;

use App\Models\Asset;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\RecertificationDigest;
use App\Notifications\RecertificationDue;
use App\Support\Permissions;
use App\Support\RecertificationWindow;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Avisos de recertificación.
 *
 * Recorre las piezas cuya fecha límite de envío está encima o ya pasó, y le
 * avisa a cada cliente lo suyo. Odessa recibe copia de cada aviso y, además,
 * un resumen semanal con la foto completa.
 *
 * Corre a diario. El resumen semanal sale sólo el día configurado, para que
 * el superadministrador reciba una sola pieza y no una por día.
 */
class SendRecertificationAlerts extends Command
{
    protected $signature = 'odessa:avisar-recertificacion
                            {--dry-run : Muestra a quién se avisaría, sin enviar nada}
                            {--force : Reenvía avisos ya enviados}';

    protected $description = 'Avisa a los clientes qué equipos deben enviar para no perder la certificación';

    public function handle(): int
    {
        $noticeDays = (int) config('odessa.recertification.notice_days');
        $dryRun = (bool) $this->option('dry-run');

        /*
         * Sin scope de organización: esto corre por consola, sin usuario
         * autenticado, y tiene que ver las piezas de todas las empresas.
         */
        $assets = Asset::withoutGlobalScope('organization')
            ->with(['catalogItem:id,description,turnaround_days', 'organization:id,name'])
            ->whereNotNull('send_by_at')
            ->whereDate('send_by_at', '<=', today()->addDays($noticeDays))
            ->whereHas('organization', fn ($q) => $q->where('is_active', true))
            ->orderBy('send_by_at')
            ->get()
            // Una pieza que ya está en el taller no necesita que le pidan que la manden.
            ->reject(fn (Asset $asset) => $this->isInWorkshop($asset));

        if ($assets->isEmpty()) {
            $this->info('No hay equipos que requieran aviso.');

            return self::SUCCESS;
        }

        $byOrganization = $assets->groupBy('organization_id');
        $digest = collect();
        $sent = 0;

        foreach ($byOrganization as $organizationId => $group) {
            $organization = $group->first()->organization;

            [$missed, $dueSoon] = $group->partition(
                fn (Asset $asset) => $asset->recertificationWindow()->state($noticeDays) === RecertificationWindow::STATE_MISSED
            );

            $pending = $this->option('force') ? $group : $group->reject(fn (Asset $a) => $this->alreadyNotified($a));

            $digest->put($organization->name, [
                'due' => $dueSoon->count(),
                'missed' => $missed->count(),
                'assets' => $group,
            ]);

            if ($pending->isEmpty()) {
                $this->line("· {$organization->name}: sin novedades (ya avisados).");

                continue;
            }

            $recipients = $this->clientRecipients($organizationId);

            if ($recipients->isEmpty()) {
                $this->warn("· {$organization->name}: {$pending->count()} equipos, pero no hay a quién avisar.");

                continue;
            }

            $this->line(sprintf(
                '· %s: %d por despachar, %d fuera de plazo -> %s',
                $organization->name,
                $dueSoon->count(),
                $missed->count(),
                $recipients->pluck('email')->implode(', '),
            ));

            if ($dryRun) {
                continue;
            }

            Notification::send(
                $recipients,
                new RecertificationDue($dueSoon, $missed, $organization->name)
            );

            $this->copyToOdessa($dueSoon, $missed, $organization);
            $this->recordAlerts($pending, $noticeDays, $recipients);

            $sent++;
        }

        if (! $dryRun && $this->digestIsDue()) {
            $this->sendDigest($digest);
        }

        $this->info($dryRun
            ? 'Simulación terminada. No se envió nada.'
            : "Avisos enviados a {$sent} empresas.");

        return self::SUCCESS;
    }

    /**
     * El equipo ya está en el taller: pedirle al cliente que lo mande sería
     * un aviso equivocado, y de los que hacen perder credibilidad al sistema.
     */
    private function isInWorkshop(Asset $asset): bool
    {
        return $asset->receptions()
            ->withoutGlobalScope('organization')
            ->whereNull('dispatched_at')
            ->exists();
    }

    /**
     * Sólo se avisa una vez por pieza, estado y vencimiento. Si la pieza se
     * recertifica y aparece un vencimiento nuevo, vuelve a avisarse.
     */
    private function alreadyNotified(Asset $asset): bool
    {
        return DB::table('recertification_alerts')
            ->where('asset_id', $asset->getKey())
            ->where('state', $asset->recertificationWindow()->state())
            ->whereDate('expires_at', $asset->expires_at)
            ->exists();
    }

    /** @param  Collection<int, Asset>  $assets */
    private function recordAlerts(Collection $assets, int $noticeDays, Collection $recipients): void
    {
        $now = now();
        $emails = $recipients->pluck('email')->implode(', ');

        foreach ($assets as $asset) {
            DB::table('recertification_alerts')->updateOrInsert(
                [
                    'asset_id' => $asset->getKey(),
                    'state' => $asset->recertificationWindow()->state($noticeDays),
                    'expires_at' => $asset->expires_at?->toDateString(),
                ],
                [
                    'organization_id' => $asset->organization_id,
                    'send_by_at' => $asset->send_by_at?->toDateString(),
                    'notified_at' => $now,
                    'recipients' => substr($emails, 0, 500),
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }
    }

    /** Usuarios activos del cliente. Operadores y consulta: todos deben enterarse. */
    private function clientRecipients(int $organizationId): Collection
    {
        return User::withoutGlobalScope('organization')
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->get();
    }

    /**
     * Copia interna. El negocio pidió que el mismo aviso que ve el cliente lo
     * vea Odessa, para poder llamarlo si no reacciona.
     */
    private function copyToOdessa(Collection $dueSoon, Collection $missed, Organization $organization): void
    {
        $address = config('odessa.recertification.copy_to');

        if (blank($address)) {
            return;
        }

        Notification::route('mail', $address)
            ->notify(new RecertificationDue($dueSoon, $missed, $organization->name));
    }

    private function digestIsDue(): bool
    {
        return today()->dayOfWeekIso === (int) config('odessa.recertification.digest_day');
    }

    private function sendDigest(\Illuminate\Support\Collection $digest): void
    {
        $admins = User::withoutGlobalScope('organization')
            ->where('is_active', true)
            ->whereHas('roles', fn ($q) => $q->where('name', Permissions::ROLE_SUPER_ADMIN))
            ->get();

        if ($admins->isEmpty()) {
            $this->warn('No hay superadministradores activos a quienes mandar el resumen.');

            return;
        }

        Notification::send($admins, new RecertificationDigest($digest));

        $this->line('Resumen semanal enviado a '.$admins->pluck('email')->implode(', '));
    }
}
