<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Reception;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ingreso directo desde el taller.
 *
 * El circuito normal es que el cliente declare el envío y el taller lo
 * autorice. Pero llegan equipos sin nada cargado, y el taller tiene que poder
 * registrarlos igual: si no, o no se trabajan o se trabajan fuera del sistema,
 * que es peor.
 */
class DirectReceptionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    /** @return array<string, mixed> */
    private function ingreso(array $overrides = []): array
    {
        return array_merge([
            'received_at' => today()->toDateString(),
            'description' => 'Válvula traída por el camión',
        ], $overrides);
    }

    public function test_el_taller_ingresa_un_equipo_nuevo_sin_envio_previo(): void
    {
        $organizacion = $this->organization('ACME');
        $usuario = $this->staffUser(Permissions::ROLE_WORKSHOP);

        $this->actingAs($usuario)
            ->post('/taller/recepciones', $this->ingreso([
                'organization_public_id' => $organizacion->public_id,
                'serial_number' => 'SN-DIRECTO-1',
            ]))
            ->assertRedirect();

        $pieza = Asset::withoutGlobalScopes()->where('serial_number', 'SN-DIRECTO-1')->first();

        $this->assertNotNull($pieza, 'No se dio de alta la pieza.');
        $this->assertSame($organizacion->getKey(), $pieza->organization_id);

        $recepcion = Reception::withoutGlobalScopes()->where('asset_id', $pieza->getKey())->first();

        $this->assertNotNull($recepcion);
        // Sin envío: es lo que distingue el ingreso directo del normal.
        $this->assertNull($recepcion->shipment_id);
        $this->assertSame($usuario->getKey(), $recepcion->received_by);
        $this->assertSame('pending', $recepcion->stage());
    }

    public function test_se_puede_ingresar_una_pieza_ya_registrada(): void
    {
        $organizacion = $this->organization();
        $pieza = $this->asset($organizacion, ['serial_number' => 'YA-EXISTE']);

        $this->actingAs($this->staffUser(Permissions::ROLE_WORKSHOP))
            ->post('/taller/recepciones', $this->ingreso([
                'organization_public_id' => $organizacion->public_id,
                'asset_public_id' => $pieza->public_id,
                'description' => null,
            ]))
            ->assertRedirect();

        // No se duplicó la pieza: se reutilizó la que ya estaba.
        $this->assertSame(1, Asset::withoutGlobalScopes()->where('serial_number', 'YA-EXISTE')->count());
        $this->assertDatabaseHas('receptions', ['asset_id' => $pieza->getKey()]);
    }

    /**
     * Un número de serie repetido casi siempre es la misma pieza volviendo al
     * taller. Darla de alta otra vez partiría su historial en dos.
     */
    public function test_no_se_duplica_una_pieza_con_el_mismo_numero_de_serie(): void
    {
        $organizacion = $this->organization();
        $this->asset($organizacion, ['serial_number' => 'REPETIDO-9']);

        $this->actingAs($this->staffUser(Permissions::ROLE_WORKSHOP))
            ->post('/taller/recepciones', $this->ingreso([
                'organization_public_id' => $organizacion->public_id,
                'serial_number' => 'REPETIDO-9',
            ]))
            ->assertSessionHasErrors('serial_number');

        $this->assertSame(1, Asset::withoutGlobalScopes()->where('serial_number', 'REPETIDO-9')->count());
    }

    public function test_hay_que_decir_de_que_cliente_es(): void
    {
        $this->actingAs($this->staffUser(Permissions::ROLE_WORKSHOP))
            ->post('/taller/recepciones', $this->ingreso())
            ->assertSessionHasErrors('organization_public_id');
    }

    public function test_hay_que_elegir_una_pieza_o_describirla(): void
    {
        $organizacion = $this->organization();

        $this->actingAs($this->staffUser(Permissions::ROLE_WORKSHOP))
            ->post('/taller/recepciones', [
                'organization_public_id' => $organizacion->public_id,
                'received_at' => today()->toDateString(),
            ])
            ->assertSessionHasErrors('description');
    }

    public function test_la_fecha_de_ingreso_no_puede_ser_futura(): void
    {
        $organizacion = $this->organization();

        $this->actingAs($this->staffUser(Permissions::ROLE_WORKSHOP))
            ->post('/taller/recepciones', $this->ingreso([
                'organization_public_id' => $organizacion->public_id,
                'received_at' => today()->addDays(3)->toDateString(),
            ]))
            ->assertSessionHasErrors('received_at');
    }

    /** No se puede ingresar contra el inventario de otra empresa. */
    public function test_no_se_puede_ingresar_una_pieza_de_otro_cliente(): void
    {
        $propia = $this->organization('PROPIA');
        $ajena = $this->organization('AJENA');

        $piezaAjena = $this->asset($ajena);

        $this->actingAs($this->staffUser(Permissions::ROLE_WORKSHOP))
            ->post('/taller/recepciones', $this->ingreso([
                'organization_public_id' => $propia->public_id,
                'asset_public_id' => $piezaAjena->public_id,
                'description' => null,
            ]))
            ->assertSessionHasErrors('asset_public_id');

        $this->assertDatabaseCount('receptions', 0);
    }

    public function test_un_cliente_no_puede_ingresar_equipos(): void
    {
        $organizacion = $this->organization();

        $this->actingAs($this->clientUser($organizacion))
            ->post('/taller/recepciones', $this->ingreso([
                'organization_public_id' => $organizacion->public_id,
            ]))
            ->assertForbidden();

        $this->assertDatabaseCount('receptions', 0);
    }

    public function test_el_ingreso_queda_en_la_auditoria(): void
    {
        $organizacion = $this->organization();

        $this->actingAs($this->staffUser(Permissions::ROLE_WORKSHOP))
            ->post('/taller/recepciones', $this->ingreso([
                'organization_public_id' => $organizacion->public_id,
            ]));

        $this->assertDatabaseHas('activity_logs', ['action' => 'reception.created']);
        $this->assertDatabaseHas('activity_logs', ['action' => 'asset.created']);
    }

    public function test_el_listado_de_piezas_para_el_formulario_respeta_la_empresa(): void
    {
        $unaEmpresa = $this->organization('UNA');
        $otraEmpresa = $this->organization('OTRA');

        $this->asset($unaEmpresa, ['serial_number' => 'DE-UNA']);
        $this->asset($otraEmpresa, ['serial_number' => 'DE-OTRA']);

        $response = $this->actingAs($this->staffUser(Permissions::ROLE_WORKSHOP))
            ->getJson("/taller/recepciones/piezas/{$unaEmpresa->public_id}");

        $response->assertOk();

        $etiquetas = collect($response->json())->pluck('label')->implode(' | ');

        $this->assertStringContainsString('DE-UNA', $etiquetas);
        $this->assertStringNotContainsString('DE-OTRA', $etiquetas);
    }
}
