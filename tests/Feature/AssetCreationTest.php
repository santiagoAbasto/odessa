<?php

namespace Tests\Feature;

use App\Enums\AssetStatus;
use App\Models\Asset;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Alta manual de equipamiento.
 *
 * Antes una pieza sólo entraba de rebote: al autorizar un envío que la
 * declaraba, o al recibirla en el taller. El negocio pidió poder cargarlas
 * antes, que es lo normal cuando un cliente entrega su listado.
 *
 * Toda alta pasa por CreateAsset, así que la guarda de serie repetida vale
 * para los tres caminos. Antes vivía sólo en el ingreso directo.
 */
class AssetCreationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    public function test_el_taller_da_de_alta_un_equipo_para_una_empresa(): void
    {
        $organizacion = $this->organization('HAL');

        $this->actingAs($this->staffUser(Permissions::ROLE_WORKSHOP))
            ->post('/activos', [
                'organization_public_id' => $organizacion->public_id,
                'description' => 'Válvula de compuerta 3"',
                'serial_number' => 'VC-9001',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $activo = Asset::withoutGlobalScopes()->where('serial_number', 'VC-9001')->firstOrFail();

        $this->assertSame($organizacion->getKey(), $activo->organization_id);
        // Sin inspección todavía: no puede figurar apta ni con vencimiento.
        $this->assertSame(AssetStatus::Unknown, $activo->status);
        $this->assertNull($activo->expires_at);
        // Se le genera código interno aunque no se haya pedido.
        $this->assertNotEmpty($activo->code);
        $this->assertDatabaseHas('activity_logs', ['action' => 'asset.created']);
    }

    public function test_no_se_da_de_alta_una_serie_que_ya_existe_en_esa_empresa(): void
    {
        $organizacion = $this->organization('HAL');
        $existente = $this->asset($organizacion);

        $this->actingAs($this->staffUser(Permissions::ROLE_WORKSHOP))
            ->post('/activos', [
                'organization_public_id' => $organizacion->public_id,
                'description' => 'La misma pieza cargada de nuevo',
                'serial_number' => $existente->serial_number,
            ])
            ->assertSessionHasErrors('serial_number');

        $this->assertSame(1, Asset::withoutGlobalScopes()
            ->where('serial_number', $existente->serial_number)
            ->count());
    }

    /** La serie identifica dentro de la empresa, no entre empresas. */
    public function test_la_misma_serie_puede_existir_en_dos_empresas_distintas(): void
    {
        $una = $this->organization('UNO');
        $otra = $this->organization('DOS');
        $existente = $this->asset($una);

        $this->actingAs($this->staffUser(Permissions::ROLE_WORKSHOP))
            ->post('/activos', [
                'organization_public_id' => $otra->public_id,
                'description' => 'Pieza distinta, misma numeración de fábrica',
                'serial_number' => $existente->serial_number,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(2, Asset::withoutGlobalScopes()
            ->where('serial_number', $existente->serial_number)
            ->count());
    }

    public function test_el_alta_exige_empresa_al_personal_interno(): void
    {
        $this->actingAs($this->staffUser(Permissions::ROLE_WORKSHOP))
            ->post('/activos', ['description' => 'Sin dueño'])
            ->assertSessionHasErrors('organization_public_id');
    }

    /** Un cliente no carga inventario: declara envíos y el taller los autoriza. */
    public function test_un_cliente_no_da_de_alta_equipos(): void
    {
        $organizacion = $this->organization('UNO');

        $this->actingAs($this->clientUser($organizacion, Permissions::ROLE_CLIENT_OPERATOR))
            ->post('/activos', [
                'description' => 'Me la cargo yo',
                'serial_number' => 'X-1',
            ])
            ->assertForbidden();
    }
}
