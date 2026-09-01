<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    /** Contraseña de prueba generada en el test; nunca una real. */
    private const PASSWORD = 'Prueba-Local-2026!';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        RateLimiter::clear('');
    }

    private function usuario(array $attributes = []): User
    {
        $user = User::factory()->create([
            'password' => Hash::make(self::PASSWORD),
            ...$attributes,
        ]);
        $user->assignRole(Permissions::ROLE_CLIENT_VIEWER);

        return $user;
    }

    public function test_la_pantalla_de_login_es_publica(): void
    {
        $this->get('/ingresar')->assertOk();
    }

    public function test_un_usuario_activo_puede_iniciar_sesion(): void
    {
        $user = $this->usuario(['organization_id' => $this->organization()->getKey()]);

        $this->post('/ingresar', ['email' => $user->email, 'password' => self::PASSWORD])
            ->assertRedirect('/panel');

        $this->assertAuthenticatedAs($user);
    }

    public function test_las_credenciales_invalidas_no_autentican(): void
    {
        $user = $this->usuario();

        $this->post('/ingresar', ['email' => $user->email, 'password' => 'no-es-la-clave'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_un_usuario_desactivado_no_puede_iniciar_sesion(): void
    {
        $user = $this->usuario(['is_active' => false]);

        $this->post('/ingresar', ['email' => $user->email, 'password' => self::PASSWORD])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    /** La sesión debe regenerarse: corta la fijación de sesión. */
    public function test_el_identificador_de_sesion_cambia_tras_autenticar(): void
    {
        $user = $this->usuario();

        $this->get('/ingresar');
        $anterior = session()->getId();

        $this->post('/ingresar', ['email' => $user->email, 'password' => self::PASSWORD]);

        $this->assertNotSame($anterior, session()->getId());
    }

    public function test_el_login_registra_la_auditoria_y_el_ultimo_acceso(): void
    {
        $user = $this->usuario();

        $this->post('/ingresar', ['email' => $user->email, 'password' => self::PASSWORD]);

        $this->assertNotNull($user->fresh()->last_login_at);
        $this->assertDatabaseHas('activity_logs', ['action' => 'auth.login', 'user_id' => $user->getKey()]);
    }

    public function test_la_auditoria_nunca_guarda_la_contrasena(): void
    {
        $user = $this->usuario();

        $this->post('/ingresar', ['email' => $user->email, 'password' => self::PASSWORD]);

        $registros = ActivityLog::all()->toJson();

        $this->assertStringNotContainsString(self::PASSWORD, $registros);
    }

    public function test_tras_cinco_intentos_fallidos_la_cuenta_queda_bloqueada(): void
    {
        $user = $this->usuario();

        for ($intento = 0; $intento < 5; $intento++) {
            $this->post('/ingresar', ['email' => $user->email, 'password' => 'incorrecta']);
        }

        $response = $this->post('/ingresar', ['email' => $user->email, 'password' => self::PASSWORD]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_el_bloqueo_es_por_correo_e_ip_y_no_afecta_a_otro_usuario(): void
    {
        $victima = $this->usuario();
        $otro = $this->usuario();

        for ($intento = 0; $intento < 6; $intento++) {
            $this->post('/ingresar', ['email' => $victima->email, 'password' => 'incorrecta']);
        }

        $this->post('/ingresar', ['email' => $otro->email, 'password' => self::PASSWORD])
            ->assertRedirect('/panel');

        $this->assertAuthenticatedAs($otro);
    }

    public function test_cerrar_sesion_invalida_la_sesion_y_queda_auditado(): void
    {
        $user = $this->usuario();

        $this->actingAs($user)->post('/salir')->assertRedirect('/');

        $this->assertGuest();
        $this->assertDatabaseHas('activity_logs', ['action' => 'auth.logout', 'user_id' => $user->getKey()]);
    }

    public function test_las_rutas_privadas_redirigen_al_login_si_no_hay_sesion(): void
    {
        foreach (['/panel', '/activos', '/informes', '/sets', '/taller/recepciones'] as $ruta) {
            $this->get($ruta)->assertRedirect('/ingresar');
        }
    }

    public function test_un_usuario_desactivado_pierde_el_acceso_en_la_siguiente_peticion(): void
    {
        $user = $this->usuario(['organization_id' => $this->organization()->getKey()]);

        $this->actingAs($user)->get('/panel')->assertOk();

        $user->update(['is_active' => false]);

        $this->actingAs($user)->get('/panel')->assertRedirect('/ingresar');
    }

    public function test_el_formulario_de_recuperacion_no_revela_si_el_correo_existe(): void
    {
        Notification::fake();

        $existente = $this->usuario();

        $conCuenta = $this->post('/recuperar-clave', ['email' => $existente->email]);
        $sinCuenta = $this->post('/recuperar-clave', ['email' => 'inexistente@ejemplo.test']);

        // Misma respuesta y mismo mensaje en ambos casos.
        $this->assertSame(
            $conCuenta->getSession()->get('status'),
            $sinCuenta->getSession()->get('status'),
        );
    }

    public function test_se_puede_restablecer_la_contrasena_con_un_token_valido(): void
    {
        Notification::fake();

        $user = $this->usuario();
        $token = Password::createToken($user);

        $nueva = 'Nueva-Clave-Segura-2026';

        $this->post('/restablecer-clave', [
            'token' => $token,
            'email' => $user->email,
            'password' => $nueva,
            'password_confirmation' => $nueva,
        ])->assertRedirect('/ingresar');

        $this->assertTrue(Hash::check($nueva, $user->fresh()->password));
        $this->assertDatabaseHas('activity_logs', ['action' => 'auth.password_reset']);
    }

    public function test_un_token_invalido_no_restablece_la_contrasena(): void
    {
        $user = $this->usuario();

        $this->post('/restablecer-clave', [
            'token' => 'token-invalido',
            'email' => $user->email,
            'password' => 'Nueva-Clave-Segura-2026',
            'password_confirmation' => 'Nueva-Clave-Segura-2026',
        ])->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check(self::PASSWORD, $user->fresh()->password));
    }

    public function test_se_rechaza_una_contrasena_debil_al_restablecer(): void
    {
        Notification::fake();

        $user = $this->usuario();
        $token = Password::createToken($user);

        $this->post('/restablecer-clave', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'corta',
            'password_confirmation' => 'corta',
        ])->assertSessionHasErrors('password');
    }
}
