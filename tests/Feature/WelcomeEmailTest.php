<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\WelcomeUser;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Correo de bienvenida al crear un usuario.
 *
 * Hay dos caminos para el mismo acceso: el enlace del correo y la contraseña
 * temporal que el administrador manda por WhatsApp. Las pruebas cuidan sobre
 * todo los bordes: que el correo nunca lleve la contraseña, que el enlace dure
 * lo que tiene que durar y ni un día más, y que al usar un camino el otro deje
 * de servir.
 */
class WelcomeEmailTest extends TestCase
{
    use RefreshDatabase;

    private const NUEVA = 'Propia-Segura-2026';

    private const OTRA = 'Distinta-Segura-2026';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    /**
     * Da de alta un usuario como lo haría el superadministrador y devuelve lo
     * que le llega por los dos caminos.
     *
     * @return array{0: User, 1: string, 2: string, 3: string} usuario, token, temporal, url
     */
    private function alta(): array
    {
        Notification::fake();

        $this->actingAs($this->staffUser(Permissions::ROLE_SUPER_ADMIN))
            ->post('/administracion/usuarios', [
                'name' => 'Usuario nuevo',
                'email' => 'nuevo@cliente.test',
                'roles' => [Permissions::ROLE_CLIENT_VIEWER],
            ])
            ->assertSessionHas('temporaryAccess');

        $temporal = session('temporaryAccess')['password'];
        $usuario = User::where('email', 'nuevo@cliente.test')->firstOrFail();

        $url = '';
        Notification::assertSentTo($usuario, WelcomeUser::class, function (WelcomeUser $aviso) use (&$url) {
            $url = $aviso->url;

            return true;
        });

        auth()->logout();

        return [$usuario, basename((string) parse_url($url, PHP_URL_PATH)), $temporal, $url];
    }

    private function elegir(string $token, string $clave = self::NUEVA): TestResponse
    {
        return $this->post('/bienvenida', [
            'token' => $token,
            'email' => 'nuevo@cliente.test',
            'password' => $clave,
            'password_confirmation' => $clave,
        ]);
    }

    public function test_el_alta_manda_la_bienvenida_y_deja_la_temporal_para_whatsapp(): void
    {
        [, $token, $temporal] = $this->alta();

        $this->assertNotSame('', $token);
        // El camino de WhatsApp sigue existiendo: el correo es además, no en lugar.
        $this->assertNotSame('', $temporal);
    }

    /** Una clave escrita en un correo queda al alcance de cualquiera que entre a esa casilla. */
    public function test_el_correo_lleva_el_enlace_y_el_usuario_pero_nunca_la_contrasena(): void
    {
        [$usuario, , $temporal, $url] = $this->alta();

        $html = (string) (new WelcomeUser($url))->toMail($usuario)->render();

        $this->assertStringContainsString($url, $html);
        $this->assertStringContainsString('nuevo@cliente.test', $html);
        $this->assertStringNotContainsString($temporal, $html);
    }

    /**
     * Es el primer contacto de un cliente con el sistema. Laravel agrega textos
     * propios al correo (el pie del botón, el aviso de derechos) que sin el
     * archivo de traducciones salen en inglés.
     */
    public function test_el_correo_sale_entero_en_castellano(): void
    {
        [$usuario, , , $url] = $this->alta();

        $html = (string) (new WelcomeUser($url))->toMail($usuario)->render();

        $this->assertStringContainsString('copie y pegue esta dirección', $html);
        $this->assertStringNotContainsString('trouble clicking', $html);
        $this->assertStringNotContainsString('web browser', $html);
    }

    public function test_desde_el_enlace_elige_su_contrasena_y_entra(): void
    {
        [$usuario, $token, , $url] = $this->alta();

        $this->get($url)->assertOk();

        $this->elegir($token)->assertRedirect('/ingresar')->assertSessionHasNoErrors();

        $usuario->refresh();
        $this->assertTrue(Hash::check(self::NUEVA, $usuario->password));
        // La eligió él: no se le vuelve a pedir que la cambie.
        $this->assertFalse($usuario->must_change_password);

        $this->post('/ingresar', ['email' => 'nuevo@cliente.test', 'password' => self::NUEVA])->assertRedirect();
        $this->get('/panel')->assertOk();
    }

    /** El "olvidé mi contraseña" vence en una hora; una bienvenida no puede. */
    public function test_el_enlace_sigue_valiendo_a_los_tres_dias(): void
    {
        [$usuario, $token] = $this->alta();

        $this->travel(3)->days();

        $this->elegir($token)->assertSessionHasNoErrors();
        $this->assertFalse($usuario->refresh()->must_change_password);
    }

    public function test_el_enlace_vence_pasada_la_semana(): void
    {
        [$usuario, $token] = $this->alta();

        $this->travel(8)->days();

        $this->elegir($token)->assertSessionHasErrors('email');
        $this->assertTrue($usuario->refresh()->must_change_password);
    }

    public function test_el_enlace_sirve_una_sola_vez(): void
    {
        [$usuario, $token] = $this->alta();

        $this->elegir($token)->assertSessionHasNoErrors();
        $this->elegir($token, self::OTRA)->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check(self::NUEVA, $usuario->refresh()->password));
    }

    /**
     * Si entró por WhatsApp, el enlace quedaría vivo en su casilla una semana
     * más: una forma de cambiarle la contraseña que ya no hace falta.
     */
    public function test_si_entra_con_la_temporal_el_enlace_deja_de_servir(): void
    {
        [$usuario, $token, $temporal] = $this->alta();

        $this->post('/ingresar', ['email' => 'nuevo@cliente.test', 'password' => $temporal])->assertRedirect();
        $this->put('/cuenta/clave', ['password' => self::NUEVA, 'password_confirmation' => self::NUEVA])
            ->assertRedirect('/panel');
        auth()->logout();

        $this->elegir($token, self::OTRA)->assertSessionHasErrors('email');
        $this->assertTrue(Hash::check(self::NUEVA, $usuario->refresh()->password));
    }

    /** Restablecer el acceso corta todo lo anterior, también un enlace sin usar. */
    public function test_restablecer_acceso_anula_el_enlace_pendiente(): void
    {
        [$usuario, $token] = $this->alta();

        $this->actingAs($this->staffUser(Permissions::ROLE_SUPER_ADMIN))
            ->post("/administracion/usuarios/{$usuario->public_id}/restablecer-acceso")
            ->assertSessionHas('temporaryAccess');
        auth()->logout();

        $this->elegir($token)->assertSessionHasErrors('email');
    }

    /**
     * Un enlace de recuperación vence en una hora. Si se pudiera validar como
     * bienvenida pasaría a vivir una semana: por eso cada uno tiene su tabla.
     */
    public function test_un_enlace_de_recuperacion_no_sirve_como_bienvenida(): void
    {
        $usuario = $this->clientUser($this->organization());
        $usuario->forceFill(['email' => 'nuevo@cliente.test'])->save();

        $recuperacion = Password::broker('users')->createToken($usuario);

        $this->elegir($recuperacion)->assertSessionHasErrors('email');
    }

    public function test_un_enlace_de_bienvenida_no_sirve_para_recuperar(): void
    {
        [, $token] = $this->alta();

        $this->post('/restablecer-clave', [
            'token' => $token,
            'email' => 'nuevo@cliente.test',
            'password' => self::NUEVA,
            'password_confirmation' => self::NUEVA,
        ])->assertSessionHasErrors('email');
    }
}
