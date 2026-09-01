<?php

namespace Tests\Feature;

use App\Models\AssetSet;
use App\Models\Reception;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Aislamiento entre organizaciones.
 *
 * Es el requisito de seguridad más crítico: un cliente no debe alcanzar los
 * datos de otro ni conociendo el identificador. Cada recurso se prueba por
 * separado, incluida la descarga de documentos.
 */
class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    public function test_un_cliente_solo_ve_sus_propios_activos_en_el_listado(): void
    {
        $propia = $this->organization('UNO');
        $ajena = $this->organization('DOS');

        $mia = $this->asset($propia, ['description' => 'Pieza propia']);
        $suya = $this->asset($ajena, ['description' => 'Pieza ajena']);

        $response = $this->actingAs($this->clientUser($propia))->get('/activos');

        $response->assertOk();
        $ids = collect($response->viewData('page')['props']['assets']['data'])->pluck('id');

        $this->assertTrue($ids->contains($mia->public_id));
        $this->assertFalse($ids->contains($suya->public_id));
    }

    public function test_acceder_a_un_activo_de_otra_organizacion_devuelve_404(): void
    {
        $ajena = $this->organization('DOS');
        $ajeno = $this->asset($ajena);

        // 404 y no 403: no se confirma siquiera que el recurso exista.
        $this->actingAs($this->clientUser($this->organization('UNO')))
            ->get("/activos/{$ajeno->public_id}")
            ->assertNotFound();
    }

    public function test_acceder_a_un_informe_de_otra_organizacion_devuelve_404(): void
    {
        $ajena = $this->organization('DOS');
        $informe = $this->reportFor($this->asset($ajena));

        $this->actingAs($this->clientUser($this->organization('UNO')))
            ->get("/informes/{$informe->public_id}")
            ->assertNotFound();
    }

    public function test_acceder_a_un_set_de_otra_organizacion_devuelve_404(): void
    {
        $ajena = $this->organization('DOS');
        $set = AssetSet::create(['organization_id' => $ajena->getKey(), 'name' => 'Set ajeno']);

        $this->actingAs($this->clientUser($this->organization('UNO')))
            ->get("/sets/{$set->public_id}")
            ->assertNotFound();
    }

    public function test_no_se_puede_descargar_un_documento_de_otra_organizacion(): void
    {
        $ajena = $this->organization('DOS');
        $archivo = $this->document($this->reportFor($this->asset($ajena)));

        // 404 y no 403: el scope global impide siquiera resolver el documento,
        // así que no se confirma que exista.
        $this->actingAs($this->clientUser($this->organization('UNO')))
            ->get("/documentos/{$archivo->public_id}/descargar")
            ->assertNotFound();
    }

    /**
     * El cliente ve sus equipos en el taller, y sólo los suyos.
     *
     * Antes esta prueba se conformaba con un 403, porque el cliente no
     * alcanzaba la pantalla. Desde que puede contestar ahí si autoriza una
     * reparación, el 403 dejó de ser la garantía y hay que comprobar el
     * aislamiento de verdad: que la recepción ajena no aparezca en la lista.
     */
    public function test_un_cliente_ve_sus_recepciones_y_ninguna_ajena(): void
    {
        $propia = $this->organization('UNO');
        $ajena = $this->organization('DOS');

        $mia = Reception::create([
            'organization_id' => $propia->getKey(),
            'asset_id' => $this->asset($propia)->getKey(),
            'received_at' => now()->subDays(3)->toDateString(),
        ]);

        $suya = Reception::create([
            'organization_id' => $ajena->getKey(),
            'asset_id' => $this->asset($ajena)->getKey(),
            'received_at' => now()->subDays(3)->toDateString(),
        ]);

        $respuesta = $this->actingAs($this->clientUser($propia, Permissions::ROLE_CLIENT_OPERATOR))
            ->get('/taller/recepciones')
            ->assertOk();

        $ids = collect($respuesta->viewData('page')['props']['receptions']['data'])
            ->pluck('id')
            ->all();

        $this->assertContains($mia->public_id, $ids);
        $this->assertNotContains($suya->public_id, $ids);
    }

    /** Ni siquiera de a una: la recepción ajena no se resuelve. */
    public function test_un_cliente_no_puede_operar_una_recepcion_ajena(): void
    {
        $ajena = $this->organization('DOS');

        $suya = Reception::create([
            'organization_id' => $ajena->getKey(),
            'asset_id' => $this->asset($ajena)->getKey(),
            'received_at' => now()->subDays(3)->toDateString(),
        ]);

        // 404 y no 403: el scope global impide resolverla, así que el cliente
        // ni siquiera confirma que exista.
        $this->actingAs($this->clientUser($this->organization('UNO'), Permissions::ROLE_CLIENT_OPERATOR))
            ->post("/taller/recepciones/{$suya->public_id}/autorizacion", ['decision' => 'approved'])
            ->assertNotFound();
    }

    public function test_el_personal_interno_si_alcanza_datos_de_cualquier_organizacion(): void
    {
        $ajena = $this->organization('DOS');
        $activo = $this->asset($ajena);

        $this->actingAs($this->staffUser(Permissions::ROLE_WORKSHOP))
            ->get("/activos/{$activo->public_id}")
            ->assertOk();
    }

    public function test_un_cliente_no_puede_gestionar_usuarios_de_otra_organizacion(): void
    {
        $propia = $this->organization('UNO');
        $ajena = $this->organization('DOS');

        $ajeno = $this->clientUser($ajena, Permissions::ROLE_CLIENT_VIEWER);

        $this->actingAs($this->clientUser($propia, Permissions::ROLE_CLIENT_OPERATOR))
            ->put("/administracion/usuarios/{$ajeno->public_id}", [
                'name' => 'Nombre cambiado',
                'email' => $ajeno->email,
            ])
            ->assertForbidden();

        $this->assertNotSame('Nombre cambiado', $ajeno->fresh()->name);
    }

    /** La administración de usuarios quedó reservada al superadministrador. */
    public function test_un_cliente_no_alcanza_la_administracion_de_usuarios(): void
    {
        $propia = $this->organization('UNO');

        foreach ([Permissions::ROLE_CLIENT_OPERATOR, Permissions::ROLE_CLIENT_VIEWER] as $rol) {
            $this->actingAs($this->clientUser($propia, $rol))
                ->get('/administracion/usuarios')
                ->assertForbidden();
        }
    }

    /** El superadministrador sí ve a todos, de cualquier organización. */
    public function test_el_superadministrador_ve_usuarios_de_todas_las_organizaciones(): void
    {
        $this->clientUser($this->organization('UNO'), Permissions::ROLE_CLIENT_OPERATOR);
        $this->clientUser($this->organization('DOS'), Permissions::ROLE_CLIENT_VIEWER);

        $response = $this->actingAs($this->staffUser(Permissions::ROLE_SUPER_ADMIN))
            ->get('/administracion/usuarios');

        $response->assertOk();
        $this->assertGreaterThanOrEqual(3, count($response->viewData('page')['props']['users']['data']));
    }
}
