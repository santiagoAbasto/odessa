<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ABM de empresas cliente.
 *
 * Es una pantalla de superadministrador: da de alta la entidad de la que
 * cuelgan usuarios, equipos e informes, así que el acceso tiene que ser
 * estrecho y el código corto irrepetible —se usa para identificar a la
 * empresa en listados y exportaciones.
 */
class OrganizationAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    public function test_el_superadministrador_crea_una_organizacion(): void
    {
        $this->actingAs($this->staffUser(Permissions::ROLE_SUPER_ADMIN))
            ->post('/administracion/organizaciones', [
                'name' => 'Halliburton Argentina',
                'short_code' => 'hal',
                'contact_email' => 'avisos@ejemplo.test',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('organizations', [
            'name' => 'Halliburton Argentina',
            // Se normaliza a mayúsculas para que "hal" y "HAL" no convivan.
            'short_code' => 'HAL',
            'is_active' => true,
        ]);
    }

    public function test_el_codigo_corto_no_se_repite(): void
    {
        $this->organization('HAL');

        $this->actingAs($this->staffUser(Permissions::ROLE_SUPER_ADMIN))
            ->post('/administracion/organizaciones', [
                'name' => 'Otra empresa',
                'short_code' => 'hal',
            ])
            ->assertSessionHasErrors('short_code');
    }

    /** Al editar, su propio código no puede contar como repetido. */
    public function test_editar_sin_cambiar_el_codigo_no_falla(): void
    {
        $organizacion = $this->organization('HAL');

        $this->actingAs($this->staffUser(Permissions::ROLE_SUPER_ADMIN))
            ->put("/administracion/organizaciones/{$organizacion->public_id}", [
                'name' => 'Halliburton SA',
                'short_code' => 'HAL',
                'is_active' => true,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('Halliburton SA', $organizacion->fresh()->name);
    }

    public function test_desactivar_conserva_equipos_e_historial(): void
    {
        $organizacion = $this->organization('HAL');
        $activo = $this->asset($organizacion);

        $this->actingAs($this->staffUser(Permissions::ROLE_SUPER_ADMIN))
            ->put("/administracion/organizaciones/{$organizacion->public_id}", [
                'name' => $organizacion->name,
                'short_code' => $organizacion->short_code,
                'is_active' => false,
            ])
            ->assertRedirect();

        $this->assertFalse($organizacion->fresh()->is_active);
        // No hay borrado en cascada: el historial tiene que sobrevivir.
        $this->assertDatabaseHas('assets', ['id' => $activo->getKey(), 'deleted_at' => null]);
    }

    public function test_el_taller_no_administra_organizaciones(): void
    {
        $this->actingAs($this->staffUser(Permissions::ROLE_WORKSHOP))
            ->get('/administracion/organizaciones')
            ->assertForbidden();
    }

    public function test_un_cliente_no_administra_organizaciones(): void
    {
        $organizacion = $this->organization('UNO');

        $this->actingAs($this->clientUser($organizacion, Permissions::ROLE_CLIENT_OPERATOR))
            ->get('/administracion/organizaciones')
            ->assertForbidden();

        $this->actingAs($this->clientUser($organizacion, Permissions::ROLE_CLIENT_OPERATOR))
            ->post('/administracion/organizaciones', ['name' => 'Mía', 'short_code' => 'MIA'])
            ->assertForbidden();
    }

    public function test_el_listado_trae_los_conteos_de_cada_empresa(): void
    {
        $organizacion = $this->organization('HAL');
        $this->asset($organizacion);
        $this->asset($organizacion);
        $this->clientUser($organizacion);

        $respuesta = $this->actingAs($this->staffUser(Permissions::ROLE_SUPER_ADMIN))
            ->get('/administracion/organizaciones')
            ->assertOk();

        $fila = collect($respuesta->viewData('page')['props']['organizations']['data'])
            ->firstWhere('shortCode', 'HAL');

        $this->assertSame(2, $fila['assetsCount']);
        $this->assertSame(1, $fila['usersCount']);
    }
}
