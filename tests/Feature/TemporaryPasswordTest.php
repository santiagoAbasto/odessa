<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Alta y recuperación de acceso sin depender del correo: el sistema genera
 * una contraseña temporal, el administrador se la pasa al usuario, y éste
 * no puede usar el sistema hasta reemplazarla.
 */
class TemporaryPasswordTest extends TestCase
{
    use RefreshDatabase;

    private const NUEVA = 'Propia-Segura-2026';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    public function test_el_alta_entrega_una_temporal_que_obliga_a_cambiarla_al_entrar(): void
    {
        $this->actingAs($this->staffUser(Permissions::ROLE_SUPER_ADMIN))->post('/administracion/usuarios', [
            'name' => 'Usuario nuevo',
            'email' => 'nuevo@cliente.test',
            'roles' => [Permissions::ROLE_CLIENT_VIEWER],
        ])->assertSessionHas('temporaryAccess');

        $temporal = session('temporaryAccess')['password'];
        auth()->logout();

        $this->post('/ingresar', ['email' => 'nuevo@cliente.test', 'password' => $temporal])->assertRedirect();

        // Con la temporal no se pasa de la pantalla de cambio.
        $this->get('/panel')->assertRedirect('/cuenta/clave');
        $this->get('/cuenta/clave')->assertOk();

        // Repetir la temporal no vale.
        $this->put('/cuenta/clave', ['password' => $temporal, 'password_confirmation' => $temporal])
            ->assertSessionHasErrors('password');

        $this->put('/cuenta/clave', ['password' => self::NUEVA, 'password_confirmation' => self::NUEVA])
            ->assertRedirect('/panel');

        $usuario = User::where('email', 'nuevo@cliente.test')->firstOrFail();
        $this->assertFalse($usuario->must_change_password);
        $this->get('/panel')->assertOk();
    }

    public function test_restablecer_invalida_la_clave_anterior_y_cierra_sus_sesiones(): void
    {
        $usuario = $this->clientUser($this->organization());
        $claveVieja = $usuario->password;
        DB::table('sessions')->insert([
            'id' => 'sesion-abierta', 'user_id' => $usuario->getKey(), 'payload' => '', 'last_activity' => time(),
        ]);

        $this->actingAs($this->staffUser(Permissions::ROLE_SUPER_ADMIN))
            ->post("/administracion/usuarios/{$usuario->public_id}/restablecer-acceso")
            ->assertSessionHas('temporaryAccess');

        $usuario->refresh();
        $this->assertNotSame($claveVieja, $usuario->password);
        $this->assertTrue($usuario->must_change_password);
        $this->assertTrue(password_verify(session('temporaryAccess')['password'], $usuario->password));
        $this->assertSame(0, DB::table('sessions')->where('user_id', $usuario->getKey())->count());
        $this->assertDatabaseHas('activity_logs', ['action' => 'user.access_reset']);
    }

    public function test_solo_quien_administra_usuarios_restablece_y_nunca_el_propio(): void
    {
        $org = $this->organization();
        $usuario = $this->clientUser($org);
        $admin = $this->staffUser(Permissions::ROLE_SUPER_ADMIN);

        $this->actingAs($this->clientUser($org))
            ->post("/administracion/usuarios/{$usuario->public_id}/restablecer-acceso")
            ->assertForbidden();

        $this->actingAs($admin)
            ->post("/administracion/usuarios/{$admin->public_id}/restablecer-acceso")
            ->assertForbidden();

        $this->assertFalse($usuario->fresh()->must_change_password);
    }

    public function test_sin_temporal_la_pantalla_de_cambio_no_sirve(): void
    {
        // Si no, cualquier sesión abierta cambiaría la clave sin conocerla.
        $usuario = $this->clientUser($this->organization());

        $this->actingAs($usuario)->get('/cuenta/clave')->assertRedirect('/panel');
        $this->actingAs($usuario)
            ->put('/cuenta/clave', ['password' => self::NUEVA, 'password_confirmation' => self::NUEVA])
            ->assertRedirect('/panel');

        $this->assertFalse(password_verify(self::NUEVA, $usuario->fresh()->password));
    }
}
