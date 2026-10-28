<?php

namespace Tests\Feature;

use App\Models\AccountRequest;
use App\Notifications\PendingAccountRequestsDigest;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Resumen de solicitudes de acceso pendientes.
 *
 * El negocio no quiere un correo por cada solicitud, sino un par de avisos al
 * día con lo que queda por aprobar. El comando `odessa:resumen-solicitudes` lo
 * arma; la campana dentro de la aplicación es inmediata y aparte.
 */
class PendingAccountRequestsDigestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    private function solicitudPendiente(array $overrides = []): AccountRequest
    {
        return AccountRequest::create([
            'name' => 'Persona Solicitante',
            'email' => 'persona@empresa.test',
            'company' => 'Empresa de prueba',
            ...$overrides,
        ]);
    }

    public function test_avisa_a_los_superadministradores_activos(): void
    {
        Notification::fake();

        $super = $this->staffUser(Permissions::ROLE_SUPER_ADMIN);
        $this->solicitudPendiente();

        $this->artisan('odessa:resumen-solicitudes')->assertSuccessful();

        Notification::assertSentTo($super, PendingAccountRequestsDigest::class);
    }

    public function test_el_resumen_incluye_cada_solicitud_pendiente(): void
    {
        Notification::fake();

        $super = $this->staffUser(Permissions::ROLE_SUPER_ADMIN);
        $this->solicitudPendiente(['email' => 'uno@empresa.test', 'company' => 'Perforaciones Uno']);
        $this->solicitudPendiente(['email' => 'dos@empresa.test', 'company' => 'Perforaciones Dos']);

        $this->artisan('odessa:resumen-solicitudes')->assertSuccessful();

        Notification::assertSentTo($super, PendingAccountRequestsDigest::class, function ($notification) use ($super) {
            $mensaje = $notification->toMail($super);
            $cuerpo = implode("\n", array_merge($mensaje->introLines, $mensaje->outroLines));

            return str_contains($cuerpo, 'uno@empresa.test')
                && str_contains($cuerpo, 'Perforaciones Uno')
                && str_contains($cuerpo, 'dos@empresa.test')
                && str_contains($cuerpo, 'Perforaciones Dos');
        });
    }

    public function test_no_envia_nada_si_no_hay_pendientes(): void
    {
        Notification::fake();

        $super = $this->staffUser(Permissions::ROLE_SUPER_ADMIN);

        $this->artisan('odessa:resumen-solicitudes')->assertSuccessful();

        Notification::assertNotSentTo($super, PendingAccountRequestsDigest::class);
    }

    /** Una solicitud ya aprobada o rechazada no es algo que quede por hacer. */
    public function test_no_incluye_las_solicitudes_ya_resueltas(): void
    {
        Notification::fake();

        $super = $this->staffUser(Permissions::ROLE_SUPER_ADMIN);
        $this->solicitudPendiente(['email' => 'pendiente@empresa.test', 'company' => 'Sigue Pendiente']);
        $this->solicitudPendiente(['email' => 'resuelta@empresa.test', 'company' => 'Ya Resuelta'])
            ->forceFill(['status' => AccountRequest::STATUS_APPROVED])->save();

        $this->artisan('odessa:resumen-solicitudes')->assertSuccessful();

        Notification::assertSentTo($super, PendingAccountRequestsDigest::class, function ($notification) use ($super) {
            $cuerpo = implode("\n", $notification->toMail($super)->introLines);

            return str_contains($cuerpo, 'pendiente@empresa.test')
                && ! str_contains($cuerpo, 'resuelta@empresa.test');
        });
    }

    public function test_no_avisa_a_clientes_ni_a_superadministradores_inactivos(): void
    {
        Notification::fake();

        $super = $this->staffUser(Permissions::ROLE_SUPER_ADMIN);
        $cliente = $this->clientUser($this->organization(), Permissions::ROLE_CLIENT_OPERATOR);
        $inactivo = $this->staffUser(Permissions::ROLE_SUPER_ADMIN);
        $inactivo->update(['is_active' => false]);

        $this->solicitudPendiente();

        $this->artisan('odessa:resumen-solicitudes')->assertSuccessful();

        Notification::assertSentTo($super, PendingAccountRequestsDigest::class);
        Notification::assertNotSentTo($cliente, PendingAccountRequestsDigest::class);
        Notification::assertNotSentTo($inactivo, PendingAccountRequestsDigest::class);
    }
}
