<?php

namespace Tests\Feature;

use App\Models\AccountRequest;
use App\Notifications\AccountRequestReceived;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Aviso de solicitud de acceso.
 *
 * El negocio pidió no tener que entrar a comprobar si hay solicitudes nuevas:
 * además de la alerta dentro de la aplicación, se envía un correo.
 */
class AccountRequestNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    private function solicitar(array $overrides = []): void
    {
        $this->post('/solicitar-acceso', [
            'name' => 'Persona Solicitante',
            'email' => 'persona@empresa.test',
            'company' => 'Empresa de prueba',
            ...$overrides,
        ]);
    }

    public function test_se_notifica_al_superadministrador(): void
    {
        Notification::fake();

        $super = $this->staffUser(Permissions::ROLE_SUPER_ADMIN);

        $this->solicitar();

        Notification::assertSentTo($super, AccountRequestReceived::class);
    }

    public function test_la_notificacion_va_por_correo_y_por_la_aplicacion(): void
    {
        Notification::fake();

        $super = $this->staffUser(Permissions::ROLE_SUPER_ADMIN);

        $this->solicitar();

        Notification::assertSentTo($super, AccountRequestReceived::class, function ($notification) use ($super) {
            $canales = $notification->via($super);

            return in_array('mail', $canales, true) && in_array('database', $canales, true);
        });
    }

    public function test_el_correo_incluye_los_datos_de_la_solicitud(): void
    {
        Notification::fake();

        $super = $this->staffUser(Permissions::ROLE_SUPER_ADMIN);

        $this->solicitar(['company' => 'Perforaciones Ejemplo', 'phone' => '+54 11 5555-5555']);

        Notification::assertSentTo($super, AccountRequestReceived::class, function ($notification) use ($super) {
            $mensaje = $notification->toMail($super);
            $cuerpo = implode(' ', array_merge($mensaje->introLines, $mensaje->outroLines));

            return str_contains($mensaje->subject, 'Perforaciones Ejemplo')
                && str_contains($cuerpo, 'persona@empresa.test');
        });
    }

    /** Un cliente nunca debe recibir el aviso de altas de otras empresas. */
    public function test_no_se_notifica_a_los_usuarios_de_cliente(): void
    {
        Notification::fake();

        $this->staffUser(Permissions::ROLE_SUPER_ADMIN);
        $cliente = $this->clientUser($this->organization(), Permissions::ROLE_CLIENT_OPERATOR);

        $this->solicitar();

        Notification::assertNotSentTo($cliente, AccountRequestReceived::class);
    }

    public function test_un_usuario_desactivado_no_recibe_el_aviso(): void
    {
        Notification::fake();

        $inactivo = $this->staffUser(Permissions::ROLE_SUPER_ADMIN);
        $inactivo->update(['is_active' => false]);

        $this->solicitar();

        Notification::assertNotSentTo($inactivo, AccountRequestReceived::class);
    }

    /** La notificación no debe impedir que la solicitud quede registrada. */
    public function test_la_solicitud_se_guarda_igualmente(): void
    {
        Notification::fake();
        $this->staffUser(Permissions::ROLE_SUPER_ADMIN);

        $this->solicitar();

        $this->assertDatabaseHas('account_requests', [
            'email' => 'persona@empresa.test',
            'status' => AccountRequest::STATUS_PENDING,
        ]);
    }
}
