<?php

namespace Tests\Feature;

use App\Models\AccountRequest;
use App\Notifications\AccountRequestConfirmation;
use App\Notifications\AccountRequestReceived;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Aviso de solicitud de acceso.
 *
 * El negocio pidió no tener que entrar a comprobar si hay solicitudes nuevas:
 * al entrar una solicitud salta la alerta inmediata dentro de la aplicación (la
 * campana). El correo no va por cada una; eso se cubre en el resumen diario
 * (ver PendingAccountRequestsDigestTest).
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

    /**
     * El aviso inmediato es sólo la campana. El correo por cada solicitud se
     * reemplazó por el resumen diario, para no llenarle la casilla al admin.
     */
    public function test_la_alerta_inmediata_va_solo_por_la_aplicacion(): void
    {
        Notification::fake();

        $super = $this->staffUser(Permissions::ROLE_SUPER_ADMIN);

        $this->solicitar();

        Notification::assertSentTo($super, AccountRequestReceived::class, function ($notification) use ($super) {
            $canales = $notification->via($super);

            return $canales === ['database'];
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

    /** Quien la pide recibe constancia en el correo que cargó, aunque no haya ningún administrador. */
    public function test_el_solicitante_recibe_una_confirmacion_en_su_correo(): void
    {
        Notification::fake();

        $this->solicitar(['email' => 'Persona@Empresa.TEST']);

        Notification::assertSentOnDemand(
            AccountRequestConfirmation::class,
            fn ($aviso, array $canales, AnonymousNotifiable $destino) => $destino->routes['mail'] === 'persona@empresa.test'
        );
    }

    /**
     * El formulario es público: sin este freno, cualquiera podría usarlo para
     * llenarle la casilla a un tercero con correos del sistema.
     */
    public function test_la_confirmacion_sale_una_vez_por_dia_y_por_correo(): void
    {
        Notification::fake();

        $this->solicitar();
        $this->solicitar(['company' => 'Otra empresa']);
        Notification::assertSentOnDemandTimes(AccountRequestConfirmation::class, 1);

        $this->travel(25)->hours();
        $this->solicitar();
        Notification::assertSentOnDemandTimes(AccountRequestConfirmation::class, 2);
    }

    /** Nunca se confirma al visitante si el correo ya tiene cuenta: el texto es siempre el mismo. */
    public function test_la_confirmacion_no_revela_si_el_correo_ya_tiene_cuenta(): void
    {
        $solicitud = AccountRequest::create(['name' => 'Persona Solicitante', 'email' => 'persona@empresa.test', 'company' => 'Empresa de prueba']);
        $sinCuenta = (string) (new AccountRequestConfirmation($solicitud))->toMail(new AnonymousNotifiable)->render();

        $this->clientUser($this->organization())->forceFill(['email' => 'persona@empresa.test'])->save();
        $conCuenta = (string) (new AccountRequestConfirmation($solicitud))->toMail(new AnonymousNotifiable)->render();

        $this->assertSame($sinCuenta, $conCuenta);
        $this->assertStringContainsString('Recibimos su solicitud', $sinCuenta);
        $this->assertStringNotContainsString('trouble clicking', $sinCuenta);
    }
}
