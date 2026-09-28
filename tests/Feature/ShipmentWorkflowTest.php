<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Organization;
use App\Models\Reception;
use App\Models\Shipment;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pre-carga de equipos por el cliente y autorización del taller.
 *
 * Regla central: hasta que el taller autoriza, un envío es sólo una
 * declaración. Nada entra al inventario ni al pipeline antes de eso.
 */
class ShipmentWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    private function envio(Organization $organizacion, string $status = Shipment::STATUS_DRAFT): Shipment
    {
        $shipment = Shipment::create([
            'organization_id' => $organizacion->getKey(),
            'reference' => 'ENV-2026-'.fake()->unique()->numerify('####'),
        ]);

        if ($status !== Shipment::STATUS_DRAFT) {
            $shipment->forceFill(['status' => $status, 'submitted_at' => now()])->save();
        }

        return $shipment;
    }

    // --- Creación por el cliente -----------------------------------------

    /** Datos mínimos que el operador debe declarar al crear un envío. */
    private function datosDeEnvio(array $overrides = []): array
    {
        return [
            'client_reference' => 'REM-1234',
            'expected_at' => now()->addDays(3)->toDateString(),
            'contact_name' => 'Persona que envía',
            ...$overrides,
        ];
    }

    public function test_el_operador_crea_un_envio_con_referencia_correlativa(): void
    {
        $organizacion = $this->organization();

        $this->actingAs($this->clientUser($organizacion, Permissions::ROLE_CLIENT_OPERATOR))
            ->post('/envios', $this->datosDeEnvio(['notes' => 'Envío de prueba']))
            ->assertRedirect();

        $shipment = Shipment::withoutGlobalScope('organization')->first();

        $this->assertNotNull($shipment);
        $this->assertSame(Shipment::STATUS_DRAFT, $shipment->status);
        $this->assertMatchesRegularExpression('/^ENV-\d{4}-\d{4}$/', $shipment->reference);
        $this->assertSame($organizacion->getKey(), $shipment->organization_id);
    }

    public function test_el_cliente_de_consulta_no_puede_crear_envios(): void
    {
        $organizacion = $this->organization();

        $this->actingAs($this->clientUser($organizacion, Permissions::ROLE_CLIENT_VIEWER))
            ->post('/envios', [])
            ->assertForbidden();
    }

    public function test_se_puede_declarar_una_pieza_ya_registrada(): void
    {
        $organizacion = $this->organization();
        $pieza = $this->asset($organizacion);
        $shipment = $this->envio($organizacion);

        $this->actingAs($this->clientUser($organizacion, Permissions::ROLE_CLIENT_OPERATOR))
            ->post("/envios/{$shipment->public_id}/equipos", [
                'asset_public_id' => $pieza->public_id,
                'special_operation' => 'Inspección general.',
            ])
            ->assertRedirect();

        $this->assertSame(1, $shipment->items()->count());
        $this->assertSame($pieza->getKey(), $shipment->items()->first()->asset_id);
    }

    public function test_se_puede_declarar_una_pieza_nueva_por_descripcion(): void
    {
        $organizacion = $this->organization();
        $shipment = $this->envio($organizacion);

        $this->actingAs($this->clientUser($organizacion, Permissions::ROLE_CLIENT_OPERATOR))
            ->post("/envios/{$shipment->public_id}/equipos", [
                'declared_description' => 'Válvula nueva sin registrar',
                'declared_serial' => 'NUEVA-001',
                'special_operation' => 'Inspección general.',
            ])
            ->assertRedirect();

        $item = $shipment->items()->first();

        $this->assertNull($item->asset_id);
        $this->assertSame('Válvula nueva sin registrar', $item->declared_description);
    }

    public function test_una_linea_sin_pieza_ni_descripcion_se_rechaza(): void
    {
        $organizacion = $this->organization();
        $shipment = $this->envio($organizacion);

        $this->actingAs($this->clientUser($organizacion, Permissions::ROLE_CLIENT_OPERATOR))
            ->post("/envios/{$shipment->public_id}/equipos", [])
            ->assertSessionHasErrors('declared_description');

        $this->assertSame(0, $shipment->items()->count());
    }

    /** Un cliente no puede declarar una pieza que pertenece a otra empresa. */
    public function test_no_se_puede_declarar_una_pieza_de_otra_organizacion(): void
    {
        $propia = $this->organization('UNO');
        $ajena = $this->organization('DOS');
        $piezaAjena = $this->asset($ajena);
        $shipment = $this->envio($propia);

        $this->actingAs($this->clientUser($propia, Permissions::ROLE_CLIENT_OPERATOR))
            ->post("/envios/{$shipment->public_id}/equipos", [
                'asset_public_id' => $piezaAjena->public_id,
            ])
            ->assertSessionHasErrors('asset_public_id');

        $this->assertSame(0, $shipment->items()->count());
    }

    // --- Envío a autorización --------------------------------------------

    public function test_un_envio_sin_equipos_no_se_puede_remitir(): void
    {
        $organizacion = $this->organization();
        $shipment = $this->envio($organizacion);

        $this->actingAs($this->clientUser($organizacion, Permissions::ROLE_CLIENT_OPERATOR))
            ->post("/envios/{$shipment->public_id}/enviar")
            ->assertForbidden();
    }

    public function test_remitir_un_envio_lo_deja_esperando_autorizacion(): void
    {
        $organizacion = $this->organization();
        $shipment = $this->envio($organizacion);
        $shipment->items()->create(['asset_id' => $this->asset($organizacion)->getKey()]);

        $this->actingAs($this->clientUser($organizacion, Permissions::ROLE_CLIENT_OPERATOR))
            ->post("/envios/{$shipment->public_id}/enviar")
            ->assertRedirect();

        $this->assertSame(Shipment::STATUS_SUBMITTED, $shipment->fresh()->status);
    }

    public function test_un_envio_remitido_ya_no_es_editable_por_el_cliente(): void
    {
        $organizacion = $this->organization();
        $shipment = $this->envio($organizacion, Shipment::STATUS_SUBMITTED);

        $this->actingAs($this->clientUser($organizacion, Permissions::ROLE_CLIENT_OPERATOR))
            ->post("/envios/{$shipment->public_id}/equipos", [
                'declared_description' => 'Intento tardío',
            ])
            ->assertForbidden();
    }

    // --- Autorización por el taller ---------------------------------------

    public function test_autorizar_crea_una_recepcion_por_cada_equipo(): void
    {
        $organizacion = $this->organization();
        $shipment = $this->envio($organizacion, Shipment::STATUS_SUBMITTED);
        $shipment->items()->create(['asset_id' => $this->asset($organizacion)->getKey()]);
        $shipment->items()->create(['asset_id' => $this->asset($organizacion)->getKey()]);

        $this->actingAs($this->staffUser(Permissions::ROLE_WORKSHOP))
            ->post("/envios/{$shipment->public_id}/revisar", ['decision' => 'authorize'])
            ->assertRedirect();

        $this->assertSame(Shipment::STATUS_AUTHORIZED, $shipment->fresh()->status);
        $this->assertSame(2, Reception::withoutGlobalScope('organization')
            ->where('shipment_id', $shipment->getKey())->count());
    }

    /** Las piezas declaradas sin registrar las da de alta el taller al autorizar. */
    public function test_autorizar_da_de_alta_las_piezas_declaradas_nuevas(): void
    {
        $organizacion = $this->organization();
        $shipment = $this->envio($organizacion, Shipment::STATUS_SUBMITTED);
        $shipment->items()->create([
            'declared_description' => 'Brida sin registrar',
            'declared_serial' => 'BR-999',
        ]);

        $this->actingAs($this->staffUser(Permissions::ROLE_WORKSHOP))
            ->post("/envios/{$shipment->public_id}/revisar", ['decision' => 'authorize']);

        $pieza = Asset::withoutGlobalScope('organization')
            ->where('serial_number', 'BR-999')->first();

        $this->assertNotNull($pieza);
        $this->assertSame($organizacion->getKey(), $pieza->organization_id);
        $this->assertNotNull($shipment->items()->first()->fresh()->reception_id);
    }

    public function test_rechazar_exige_un_motivo(): void
    {
        $organizacion = $this->organization();
        $shipment = $this->envio($organizacion, Shipment::STATUS_SUBMITTED);

        $this->actingAs($this->staffUser(Permissions::ROLE_WORKSHOP))
            ->post("/envios/{$shipment->public_id}/revisar", ['decision' => 'reject'])
            ->assertSessionHasErrors('review_notes');

        $this->assertSame(Shipment::STATUS_SUBMITTED, $shipment->fresh()->status);
    }

    public function test_rechazar_deja_el_motivo_visible_y_no_crea_recepciones(): void
    {
        $organizacion = $this->organization();
        $shipment = $this->envio($organizacion, Shipment::STATUS_SUBMITTED);
        $shipment->items()->create(['asset_id' => $this->asset($organizacion)->getKey()]);

        $this->actingAs($this->staffUser(Permissions::ROLE_WORKSHOP))
            ->post("/envios/{$shipment->public_id}/revisar", [
                'decision' => 'reject',
                'review_notes' => 'La documentación no coincide con lo declarado.',
            ])
            ->assertRedirect();

        $fresco = $shipment->fresh();
        $this->assertSame(Shipment::STATUS_REJECTED, $fresco->status);
        $this->assertSame('La documentación no coincide con lo declarado.', $fresco->review_notes);
        $this->assertSame(0, Reception::withoutGlobalScope('organization')->count());
    }

    public function test_un_cliente_no_puede_autorizar_su_propio_envio(): void
    {
        $organizacion = $this->organization();
        $shipment = $this->envio($organizacion, Shipment::STATUS_SUBMITTED);

        $this->actingAs($this->clientUser($organizacion, Permissions::ROLE_CLIENT_OPERATOR))
            ->post("/envios/{$shipment->public_id}/revisar", ['decision' => 'authorize'])
            ->assertForbidden();

        $this->assertSame(Shipment::STATUS_SUBMITTED, $shipment->fresh()->status);
    }

    public function test_no_se_puede_autorizar_dos_veces(): void
    {
        $organizacion = $this->organization();
        $shipment = $this->envio($organizacion, Shipment::STATUS_AUTHORIZED);

        $this->actingAs($this->staffUser(Permissions::ROLE_WORKSHOP))
            ->post("/envios/{$shipment->public_id}/revisar", ['decision' => 'authorize'])
            ->assertForbidden();
    }

    public function test_autorizar_queda_registrado_en_la_auditoria(): void
    {
        $organizacion = $this->organization();
        $shipment = $this->envio($organizacion, Shipment::STATUS_SUBMITTED);
        $shipment->items()->create(['asset_id' => $this->asset($organizacion)->getKey()]);

        $this->actingAs($this->staffUser(Permissions::ROLE_WORKSHOP))
            ->post("/envios/{$shipment->public_id}/revisar", ['decision' => 'authorize']);

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'shipment.authorized',
            'subject_id' => $shipment->getKey(),
        ]);
    }

    // --- Aislamiento -------------------------------------------------------

    /** El negocio pidió que el operador complete la declaración entera. */
    public function test_crear_un_envio_exige_remito_fecha_y_contacto(): void
    {
        $organizacion = $this->organization();

        $this->actingAs($this->clientUser($organizacion, Permissions::ROLE_CLIENT_OPERATOR))
            ->post('/envios', [])
            ->assertSessionHasErrors(['client_reference', 'expected_at', 'contact_name']);

        $this->assertSame(0, Shipment::withoutGlobalScope('organization')->count());
    }

    public function test_la_empresa_y_la_referencia_no_se_piden_al_operador(): void
    {
        $propia = $this->organization('UNO');
        $ajena = $this->organization('DOS');

        // Aunque se manipule el formulario, la empresa sale del usuario.
        $this->actingAs($this->clientUser($propia, Permissions::ROLE_CLIENT_OPERATOR))
            ->post('/envios', $this->datosDeEnvio([
                'organization_id' => $ajena->getKey(),
                'reference' => 'ENV-FALSA-0001',
            ]));

        $shipment = Shipment::withoutGlobalScope('organization')->first();

        $this->assertSame($propia->getKey(), $shipment->organization_id);
        $this->assertNotSame('ENV-FALSA-0001', $shipment->reference);
    }

    public function test_se_guardan_los_datos_de_contacto_y_transporte(): void
    {
        $organizacion = $this->organization();

        $this->actingAs($this->clientUser($organizacion, Permissions::ROLE_CLIENT_OPERATOR))
            ->post('/envios', $this->datosDeEnvio([
                'contact_phone' => '+54 11 5555-5555',
                'carrier' => 'Transporte Sur',
                'origin' => 'Base Norte',
            ]));

        $shipment = Shipment::withoutGlobalScope('organization')->first();

        $this->assertSame('REM-1234', $shipment->client_reference);
        $this->assertSame('Transporte Sur', $shipment->carrier);
        $this->assertSame('Base Norte', $shipment->origin);
    }

    /** El listado respeta el aislamiento: cada cliente ve sólo lo suyo. */
    public function test_el_listado_de_un_cliente_solo_trae_sus_envios(): void
    {
        $propia = $this->organization('UNO');
        $ajena = $this->organization('DOS');

        $mio = $this->envio($propia);
        $ajeno = $this->envio($ajena);

        $response = $this->actingAs($this->clientUser($propia, Permissions::ROLE_CLIENT_OPERATOR))
            ->get('/envios');

        $ids = collect($response->viewData('page')['props']['shipments']['data'])->pluck('id');

        $this->assertTrue($ids->contains($mio->public_id));
        $this->assertFalse($ids->contains($ajeno->public_id));
    }

    /** El superadministrador ve los envíos de todas las empresas. */
    public function test_el_superadministrador_ve_los_envios_de_todas_las_empresas(): void
    {
        $unaEmpresa = $this->envio($this->organization('UNO'));
        $otraEmpresa = $this->envio($this->organization('DOS'));

        $response = $this->actingAs($this->staffUser(Permissions::ROLE_SUPER_ADMIN))
            ->get('/envios');

        $ids = collect($response->viewData('page')['props']['shipments']['data'])->pluck('id');

        $this->assertTrue($ids->contains($unaEmpresa->public_id));
        $this->assertTrue($ids->contains($otraEmpresa->public_id));
    }

    public function test_un_cliente_no_ve_los_envios_de_otra_organizacion(): void
    {
        $propia = $this->organization('UNO');
        $ajena = $this->organization('DOS');
        $ajeno = $this->envio($ajena);

        $this->actingAs($this->clientUser($propia, Permissions::ROLE_CLIENT_OPERATOR))
            ->get("/envios/{$ajeno->public_id}")
            ->assertNotFound();
    }
}
