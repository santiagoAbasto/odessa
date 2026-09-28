<?php

namespace Tests\Feature;

use App\Models\Shipment;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Permisos adicionales por usuario: el superadmin puede sumarle a un usuario
 * cliente permisos puntuales por encima de su rol —por ejemplo, que uno de
 * consulta cargue envíos—, siempre dentro de una lista pensada para clientes.
 */
class UserExtraPermissionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    private function editar(User $user, array $extra): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->staffUser(Permissions::ROLE_SUPER_ADMIN))
            ->put("/administracion/usuarios/{$user->public_id}", [
                'name' => $user->name,
                'email' => $user->email,
                'is_active' => true,
                'roles' => $user->getRoleNames()->all(),
                'extra_permissions' => $extra,
            ]);
    }

    public function test_un_usuario_de_consulta_con_el_permiso_puede_cargar_envios(): void
    {
        $org = $this->organization();
        $consulta = $this->clientUser($org, Permissions::ROLE_CLIENT_VIEWER);

        $this->actingAs($consulta)->post('/envios', [])->assertForbidden();

        $this->editar($consulta, [Permissions::SHIPMENTS_CREATE])->assertRedirect()->assertSessionHasNoErrors();

        $this->actingAs($consulta->fresh())->post('/envios', [
            'client_reference' => 'REM-1',
            'expected_at' => now()->addDays(2)->toDateString(),
            'contact_name' => 'Quien envía',
        ])->assertRedirect();

        $this->assertSame(1, Shipment::withoutGlobalScope('organization')->count());
        $this->assertDatabaseHas('activity_logs', ['action' => 'user.permissions_updated']);
    }

    public function test_no_se_otorga_un_permiso_fuera_de_la_lista_para_clientes(): void
    {
        $consulta = $this->clientUser($this->organization(), Permissions::ROLE_CLIENT_VIEWER);

        $this->editar($consulta, [Permissions::USERS_MANAGE])->assertSessionHasErrors('extra_permissions.0');

        $this->assertFalse($consulta->fresh()->hasDirectPermission(Permissions::USERS_MANAGE));
    }

    public function test_al_personal_interno_no_se_le_guardan_permisos_adicionales(): void
    {
        $taller = $this->staffUser(Permissions::ROLE_WORKSHOP);

        $this->editar($taller, [Permissions::SHIPMENTS_CREATE])->assertRedirect();

        $this->assertCount(0, $taller->fresh()->getDirectPermissions());
    }

    public function test_quitarlo_revoca_sin_tocar_otros_permisos_directos(): void
    {
        $consulta = $this->clientUser($this->organization(), Permissions::ROLE_CLIENT_VIEWER);
        $consulta->givePermissionTo(Permissions::SHIPMENTS_CREATE, Permissions::USERS_VIEW);

        $this->editar($consulta, [])->assertRedirect();

        $fresco = $consulta->fresh();
        $this->assertFalse($fresco->hasDirectPermission(Permissions::SHIPMENTS_CREATE));
        // Lo que no es de la lista no lo administra esta pantalla.
        $this->assertTrue($fresco->hasDirectPermission(Permissions::USERS_VIEW));
    }

    public function test_el_listado_muestra_los_permisos_adicionales(): void
    {
        $consulta = $this->clientUser($this->organization(), Permissions::ROLE_CLIENT_VIEWER);
        $consulta->givePermissionTo(Permissions::RECEPTIONS_AUTHORIZE_REPAIR);

        $props = $this->actingAs($this->staffUser(Permissions::ROLE_SUPER_ADMIN))
            ->get('/administracion/usuarios')
            ->viewData('page')['props'];

        $fila = collect($props['users']['data'])->firstWhere('id', $consulta->public_id);

        $this->assertSame([Permissions::RECEPTIONS_AUTHORIZE_REPAIR], $fila['extraPermissions']);
        $this->assertTrue($props['can']['grantPermissions']);
        $this->assertContains(Permissions::SHIPMENTS_CREATE, $props['roleGrants'][Permissions::ROLE_CLIENT_OPERATOR]);
    }

    /**
     * Regresión: el selector armaba `public_id as id` y Eloquent lo casteaba
     * a entero —un UUIDv7 «01a0…» daba 1 para todas—, así que todo usuario
     * cliente creado desde la pantalla caía en la organización 1.
     */
    public function test_el_selector_de_organizaciones_usa_el_id_publico_de_cada_una(): void
    {
        $una = $this->organization();
        $otra = $this->organization();

        $opciones = collect($this->actingAs($this->staffUser(Permissions::ROLE_SUPER_ADMIN))
            ->get('/administracion/usuarios')
            ->viewData('page')['props']['organizations'])->pluck('id');

        $this->assertEqualsCanonicalizing([$una->public_id, $otra->public_id], $opciones->all());
    }

    /**
     * El caso que dio el negocio: el superadmin crea la organización y sus
     * usuarios. La pantalla manda la organización por su identificador
     * público; antes la validación esperaba el número interno y el alta de un
     * usuario cliente desde la interfaz fallaba.
     */
    public function test_el_superadmin_crea_un_usuario_cliente_con_permisos_desde_la_pantalla(): void
    {
        Notification::fake();
        $org = $this->organization();

        $this->actingAs($this->staffUser(Permissions::ROLE_SUPER_ADMIN))
            ->post('/administracion/usuarios', [
                'name' => 'Jefe de base',
                'email' => 'jefe@cliente.test',
                'organization_id' => $org->public_id,
                'roles' => [Permissions::ROLE_CLIENT_VIEWER],
                'extra_permissions' => [Permissions::RECEPTIONS_AUTHORIZE_REPAIR],
            ])
            ->assertSessionHasNoErrors();

        $creado = User::where('email', 'jefe@cliente.test')->firstOrFail();

        $this->assertSame($org->getKey(), $creado->organization_id);
        $this->assertTrue($creado->can(Permissions::RECEPTIONS_AUTHORIZE_REPAIR));
    }
}
