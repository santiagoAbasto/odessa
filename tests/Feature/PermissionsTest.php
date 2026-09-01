<?php

namespace Tests\Feature;

use App\Enums\ReportStatus;
use App\Models\Reception;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Matriz de permisos con los cuatro roles confirmados por el negocio:
 * superadministrador, taller, cliente operador y cliente consulta.
 *
 * Confirma que ocultar un botón no es la defensa: la ruta responde 403 aunque
 * se llegue directamente por URL.
 */
class PermissionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    // --- Alcance de los roles de cliente ---------------------------------

    public function test_un_cliente_de_consulta_no_alcanza_la_administracion(): void
    {
        $user = $this->clientUser($this->organization(), Permissions::ROLE_CLIENT_VIEWER);

        $this->actingAs($user)->get('/administracion/usuarios')->assertForbidden();
        $this->actingAs($user)->get('/administracion/auditoria')->assertForbidden();
        $this->actingAs($user)->get('/administracion/solicitudes')->assertForbidden();
    }

    /**
     * El cliente mira el taller pero no lo opera.
     *
     * Ve dónde está su equipo —y ahí contesta si autoriza una reparación, que
     * es lo único que decide él— pero ninguna de las acciones del taller le
     * corresponde: ni empezar el trabajo, ni dictaminar, ni despachar.
     */
    public function test_un_cliente_ve_el_taller_pero_no_ejecuta_sus_acciones(): void
    {
        foreach ([Permissions::ROLE_CLIENT_VIEWER, Permissions::ROLE_CLIENT_OPERATOR] as $rol) {
            $organizacion = $this->organization();
            $user = $this->clientUser($organizacion, $rol);

            $this->actingAs($user)->get('/taller/recepciones')->assertOk();

            $recepcion = Reception::create([
                'organization_id' => $organizacion->getKey(),
                'asset_id' => $this->asset($organizacion)->getKey(),
                'received_at' => now()->subDays(3)->toDateString(),
            ]);

            foreach (['empezar', 'dictamen', 'consultar-reparacion', 'reparar', 'reparada', 'despachar'] as $accion) {
                $this->actingAs($user)
                    ->post("/taller/recepciones/{$recepcion->public_id}/{$accion}")
                    ->assertForbidden();
            }
        }
    }

    /** Dar de alta una recepción es del taller: el cliente declara envíos. */
    public function test_un_cliente_no_registra_recepciones(): void
    {
        $organizacion = $this->organization();

        $this->actingAs($this->clientUser($organizacion, Permissions::ROLE_CLIENT_OPERATOR))
            ->post('/taller/recepciones', [])
            ->assertForbidden();
    }

    public function test_ambos_roles_de_cliente_consultan_activos_informes_y_sets(): void
    {
        foreach ([Permissions::ROLE_CLIENT_VIEWER, Permissions::ROLE_CLIENT_OPERATOR] as $rol) {
            $user = $this->clientUser($this->organization(), $rol);

            $this->actingAs($user)->get('/activos')->assertOk();
            $this->actingAs($user)->get('/informes')->assertOk();
            $this->actingAs($user)->get('/sets')->assertOk();
        }
    }

    /** La única diferencia entre operador y consulta es la pre-carga de envíos. */
    public function test_solo_el_operador_puede_crear_envios(): void
    {
        $operador = $this->clientUser($this->organization(), Permissions::ROLE_CLIENT_OPERATOR);
        $consulta = $this->clientUser($this->organization(), Permissions::ROLE_CLIENT_VIEWER);

        $this->assertTrue($operador->can(Permissions::SHIPMENTS_CREATE));
        $this->assertFalse($consulta->can(Permissions::SHIPMENTS_CREATE));

        // Ambos ven el listado de envíos de su empresa.
        $this->assertTrue($operador->can(Permissions::SHIPMENTS_VIEW));
        $this->assertTrue($consulta->can(Permissions::SHIPMENTS_VIEW));
    }

    public function test_ningun_cliente_puede_editar_el_inventario(): void
    {
        foreach ([Permissions::ROLE_CLIENT_VIEWER, Permissions::ROLE_CLIENT_OPERATOR] as $rol) {
            $user = $this->clientUser($this->organization(), $rol);

            $this->assertFalse($user->can(Permissions::ASSETS_CREATE), "{$rol} no debe crear activos");
            $this->assertFalse($user->can(Permissions::ASSETS_UPDATE), "{$rol} no debe editar activos");
            $this->assertFalse($user->can(Permissions::REPORTS_CREATE), "{$rol} no debe crear informes");
            $this->assertFalse($user->can(Permissions::DOCUMENTS_UPLOAD), "{$rol} no debe subir archivos");
        }
    }

    // --- Alcance del taller ----------------------------------------------

    public function test_el_taller_ingresa_y_edita_la_base_de_datos(): void
    {
        $taller = $this->staffUser(Permissions::ROLE_WORKSHOP);

        foreach ([
            Permissions::ASSETS_CREATE,
            Permissions::ASSETS_UPDATE,
            Permissions::REPORTS_CREATE,
            Permissions::REPORTS_UPDATE,
            Permissions::REPORTS_FINALIZE,
            Permissions::DOCUMENTS_UPLOAD,
            Permissions::RECEPTIONS_RELEASE,
            Permissions::RECEPTIONS_DISPATCH,
            Permissions::SHIPMENTS_AUTHORIZE,
            Permissions::SETS_MANAGE,
        ] as $permiso) {
            $this->assertTrue($taller->can($permiso), "El taller debería tener {$permiso}");
        }
    }

    /** El taller opera la base de datos, pero no administra usuarios ni roles. */
    public function test_el_taller_no_administra_usuarios_ni_roles(): void
    {
        $taller = $this->staffUser(Permissions::ROLE_WORKSHOP);

        $this->assertFalse($taller->can(Permissions::USERS_MANAGE));
        $this->assertFalse($taller->can(Permissions::ROLES_MANAGE));
        $this->assertFalse($taller->can(Permissions::ACCOUNT_REQUESTS_MANAGE));

        $this->actingAs($taller)->get('/administracion/usuarios')->assertForbidden();
    }

    public function test_el_taller_libera_y_despacha_sin_separacion_de_funciones(): void
    {
        $taller = $this->staffUser(Permissions::ROLE_WORKSHOP);

        $this->assertTrue($taller->can(Permissions::RECEPTIONS_RELEASE));
        $this->assertTrue($taller->can(Permissions::RECEPTIONS_DISPATCH));
    }

    public function test_el_taller_puede_finalizar_un_informe(): void
    {
        $organizacion = $this->organization();
        $informe = $this->reportFor($this->asset($organizacion), status: ReportStatus::Draft);

        $this->actingAs($this->staffUser(Permissions::ROLE_WORKSHOP))
            ->post("/informes/{$informe->public_id}/finalizar")
            ->assertRedirect();
    }

    public function test_un_cliente_no_puede_finalizar_un_informe(): void
    {
        $organizacion = $this->organization();
        $informe = $this->reportFor($this->asset($organizacion), status: ReportStatus::Draft);

        $this->actingAs($this->clientUser($organizacion, Permissions::ROLE_CLIENT_OPERATOR))
            ->post("/informes/{$informe->public_id}/finalizar")
            ->assertForbidden();
    }

    // --- Superadministrador ----------------------------------------------

    public function test_el_superadministrador_hace_todo(): void
    {
        $super = $this->staffUser(Permissions::ROLE_SUPER_ADMIN);

        foreach (Permissions::all() as $permiso) {
            $this->assertTrue($super->can($permiso), "Falta el permiso {$permiso}");
        }
    }

    // --- Prevención de escalada de privilegios ----------------------------

    public function test_un_cliente_no_puede_otorgarse_un_rol_interno(): void
    {
        $organizacion = $this->organization();

        // Se le concede la gestión de usuarios para probar el límite real:
        // aun con el permiso, no puede salir de los roles de cliente.
        $admin = $this->clientUser($organizacion, Permissions::ROLE_CLIENT_OPERATOR);
        $admin->givePermissionTo(Permissions::USERS_MANAGE, Permissions::USERS_VIEW);

        $this->actingAs($admin)->post('/administracion/usuarios', [
            'name' => 'Usuario nuevo',
            'email' => 'nuevo@cliente.test',
            'roles' => [Permissions::ROLE_WORKSHOP],
        ]);

        $creado = User::where('email', 'nuevo@cliente.test')->first();

        $this->assertNotNull($creado);
        $this->assertFalse($creado->hasRole(Permissions::ROLE_WORKSHOP));
        $this->assertSame($organizacion->getKey(), $creado->organization_id);
    }

    public function test_un_cliente_no_puede_crear_usuarios_en_otra_organizacion(): void
    {
        $propia = $this->organization('UNO');
        $ajena = $this->organization('DOS');

        $admin = $this->clientUser($propia, Permissions::ROLE_CLIENT_OPERATOR);
        $admin->givePermissionTo(Permissions::USERS_MANAGE, Permissions::USERS_VIEW);

        $this->actingAs($admin)->post('/administracion/usuarios', [
            'name' => 'Infiltrado',
            'email' => 'infiltrado@cliente.test',
            'organization_id' => $ajena->getKey(),
            'roles' => [Permissions::ROLE_CLIENT_VIEWER],
        ]);

        $creado = User::where('email', 'infiltrado@cliente.test')->first();

        $this->assertSame($propia->getKey(), $creado?->organization_id);
    }

    public function test_un_usuario_nuevo_recibe_el_enlace_para_definir_su_clave(): void
    {
        Notification::fake();

        $admin = $this->staffUser(Permissions::ROLE_SUPER_ADMIN);

        $this->actingAs($admin)->post('/administracion/usuarios', [
            'name' => 'Usuario invitado',
            'email' => 'invitado@cliente.test',
            'roles' => [Permissions::ROLE_CLIENT_VIEWER],
        ])->assertSessionHas('success');

        $creado = User::where('email', 'invitado@cliente.test')->firstOrFail();
        Notification::assertSentTo($creado, ResetPassword::class);
    }

    public function test_nadie_puede_desactivarse_a_si_mismo(): void
    {
        $admin = $this->staffUser(Permissions::ROLE_SUPER_ADMIN);

        $this->actingAs($admin)
            ->delete("/administracion/usuarios/{$admin->public_id}")
            ->assertForbidden();

        $this->assertTrue($admin->fresh()->is_active);
    }
}
