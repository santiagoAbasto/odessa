<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\AssetSet;
use App\Models\Reception;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * De qué cliente es cada equipo.
 *
 * Devolución textual del negocio: «cada producto no figura de qué cliente es,
 * cuando lo queremos ver desde Odessa». Desde adentro conviven los equipos de
 * todas las empresas en las mismas pantallas, así que sin el dueño a la vista
 * el inventario es una sola bolsa indistinguible.
 *
 * Al cliente no se le muestra: todo lo que ve ya es suyo, y la columna sería
 * ruido en cada fila.
 */
class OwningClientVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    public function test_el_listado_de_activos_dice_de_quien_es_cada_pieza(): void
    {
        $organizacion = $this->organization('ACME');
        $this->asset($organizacion, ['kind' => Asset::KIND_PART]);

        $response = $this->actingAs($this->staffUser(Permissions::ROLE_WORKSHOP))
            ->get('/activos');

        $props = $response->viewData('page')['props'];
        $filas = collect($props['assets']['data']);

        $this->assertTrue($props['showOrganization']);
        $this->assertTrue(
            $filas->contains(fn (array $fila) => $fila['organization'] === $organizacion->name),
            'El listado de activos no trae la empresa dueña.'
        );
    }

    public function test_la_ficha_del_equipo_dice_de_quien_es(): void
    {
        $organizacion = $this->organization('ACME');
        $pieza = $this->asset($organizacion);

        $response = $this->actingAs($this->staffUser(Permissions::ROLE_WORKSHOP))
            ->get("/activos/{$pieza->public_id}");

        $this->assertSame(
            $organizacion->name,
            $response->viewData('page')['props']['asset']['organization']
        );
    }

    public function test_las_recepciones_del_taller_dicen_de_quien_es_cada_pieza(): void
    {
        $organizacion = $this->organization('ACME');
        $pieza = $this->asset($organizacion);

        Reception::create([
            'organization_id' => $organizacion->getKey(),
            'asset_id' => $pieza->getKey(),
            'received_at' => today()->toDateString(),
        ]);

        $response = $this->actingAs($this->staffUser(Permissions::ROLE_WORKSHOP))
            ->get('/taller/recepciones');

        $props = $response->viewData('page')['props'];

        $this->assertTrue($props['showOrganization']);
        $this->assertSame(
            $organizacion->name,
            collect($props['receptions']['data'])->first()['asset']['organization']
        );
    }

    public function test_los_informes_dicen_de_quien_son(): void
    {
        $organizacion = $this->organization('ACME');
        $pieza = $this->asset($organizacion);
        $this->reportFor($pieza);

        $response = $this->actingAs($this->staffUser(Permissions::ROLE_WORKSHOP))
            ->get('/informes');

        $props = $response->viewData('page')['props'];

        $this->assertTrue($props['showOrganization']);
        $this->assertTrue(
            collect($props['reports']['data'])
                ->contains(fn (array $fila) => $fila['organization'] === $organizacion->name),
            'El listado de informes no trae la empresa dueña.'
        );
    }

    public function test_los_sets_dicen_de_quien_son(): void
    {
        $organizacion = $this->organization('ACME');

        AssetSet::create([
            'organization_id' => $organizacion->getKey(),
            'name' => 'Conjunto de prueba',
        ]);

        $response = $this->actingAs($this->staffUser(Permissions::ROLE_WORKSHOP))
            ->get('/sets');

        $props = $response->viewData('page')['props'];

        $this->assertTrue($props['showOrganization']);
        $this->assertSame(
            $organizacion->name,
            collect($props['sets']['data'])->first()['organization']
        );
    }

    /** A un cliente la columna le sobra: todo lo que ve ya es suyo. */
    public function test_al_cliente_no_se_le_muestra_la_columna(): void
    {
        $organizacion = $this->organization('ACME');
        $this->asset($organizacion, ['kind' => Asset::KIND_PART]);

        $response = $this->actingAs($this->clientUser($organizacion))->get('/activos');

        $this->assertFalse($response->viewData('page')['props']['showOrganization']);
    }

    /**
     * El filtro por cliente es la otra mitad del pedido: no alcanza con ver
     * de quién es cada fila, hay que poder quedarse sólo con las de uno.
     */
    public function test_se_puede_filtrar_el_inventario_por_cliente(): void
    {
        $unaEmpresa = $this->organization('UNA');
        $otraEmpresa = $this->organization('OTRA');

        $this->asset($unaEmpresa, ['kind' => Asset::KIND_PART, 'serial_number' => 'DE-UNA']);
        $this->asset($otraEmpresa, ['kind' => Asset::KIND_PART, 'serial_number' => 'DE-OTRA']);

        $response = $this->actingAs($this->staffUser(Permissions::ROLE_WORKSHOP))
            ->get("/activos?organization={$unaEmpresa->public_id}");

        $seriales = collect($response->viewData('page')['props']['assets']['data'])
            ->pluck('serialNumber');

        $this->assertTrue($seriales->contains('DE-UNA'));
        $this->assertFalse($seriales->contains('DE-OTRA'));
    }

    public function test_el_filtro_por_cliente_no_deja_a_un_cliente_espiar_a_otro(): void
    {
        $propia = $this->organization('PROPIA');
        $ajena = $this->organization('AJENA');

        $this->asset($propia, ['kind' => Asset::KIND_PART, 'serial_number' => 'PROPIA-1']);
        $this->asset($ajena, ['kind' => Asset::KIND_PART, 'serial_number' => 'AJENA-1']);

        // Un cliente pide explícitamente el inventario de la otra empresa.
        $response = $this->actingAs($this->clientUser($propia))
            ->get("/activos?organization={$ajena->public_id}");

        $seriales = collect($response->viewData('page')['props']['assets']['data'])
            ->pluck('serialNumber');

        // El global scope manda: el filtro no puede ampliar lo que ve.
        $this->assertFalse($seriales->contains('AJENA-1'));
    }
}
