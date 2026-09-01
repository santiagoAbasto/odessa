<?php

namespace Tests\Feature;

use App\Actions\RecalculateAssetStatus;
use App\Enums\AssetStatus;
use App\Enums\InspectionResult;
use App\Models\Asset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Listados: filtros, búsqueda, orden y paginación del lado del servidor. */
class AssetBrowsingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    public function test_el_listado_separa_partes_de_genericos(): void
    {
        $organizacion = $this->organization();
        $parte = $this->asset($organizacion, ['kind' => Asset::KIND_PART]);
        $generico = $this->asset($organizacion, ['kind' => Asset::KIND_GENERIC]);

        $usuario = $this->clientUser($organizacion);

        $partes = collect(
            $this->actingAs($usuario)->get('/activos?kind=part')->viewData('page')['props']['assets']['data']
        )->pluck('id');

        $genericos = collect(
            $this->actingAs($usuario)->get('/activos?kind=generic')->viewData('page')['props']['assets']['data']
        )->pluck('id');

        $this->assertTrue($partes->contains($parte->public_id));
        $this->assertFalse($partes->contains($generico->public_id));
        $this->assertTrue($genericos->contains($generico->public_id));
    }

    public function test_la_busqueda_libre_encuentra_por_numero_de_serie(): void
    {
        $organizacion = $this->organization();
        $buscada = $this->asset($organizacion, ['serial_number' => 'SERIE-UNICA-77']);
        $otra = $this->asset($organizacion, ['serial_number' => 'OTRA-99']);

        $response = $this->actingAs($this->clientUser($organizacion))
            ->get('/activos?search=UNICA-77');

        $ids = collect($response->viewData('page')['props']['assets']['data'])->pluck('id');

        $this->assertTrue($ids->contains($buscada->public_id));
        $this->assertFalse($ids->contains($otra->public_id));
    }

    public function test_los_comodines_de_like_se_escapan_en_la_busqueda(): void
    {
        $organizacion = $this->organization();
        $this->asset($organizacion, ['serial_number' => 'ABC-123']);

        // Un '%' literal no debe comportarse como comodín y traer todo.
        $response = $this->actingAs($this->clientUser($organizacion))->get('/activos?search=%');

        $this->assertCount(0, $response->viewData('page')['props']['assets']['data']);
    }

    public function test_se_puede_filtrar_por_estado(): void
    {
        $organizacion = $this->organization();

        $apta = $this->asset($organizacion);
        $this->reportFor($apta, InspectionResult::Apto, expiresAt: now()->addYear()->toDateString());
        app(RecalculateAssetStatus::class)($apta);

        $noApta = $this->asset($organizacion);
        $this->reportFor($noApta, InspectionResult::NoApto);
        app(RecalculateAssetStatus::class)($noApta);

        $response = $this->actingAs($this->clientUser($organizacion))
            ->get('/activos?status='.AssetStatus::Unfit->value);

        $ids = collect($response->viewData('page')['props']['assets']['data'])->pluck('id');

        $this->assertTrue($ids->contains($noApta->public_id));
        $this->assertFalse($ids->contains($apta->public_id));
    }

    public function test_una_columna_de_orden_no_permitida_se_ignora(): void
    {
        $organizacion = $this->organization();
        $this->asset($organizacion);

        // Intento de inyección por el parámetro de orden: debe caer al default.
        $this->actingAs($this->clientUser($organizacion))
            ->get('/activos?sort=assets.id;DROP TABLE assets&direction=asc')
            ->assertOk();

        $this->assertDatabaseCount('assets', 1);
    }

    public function test_la_paginacion_es_del_lado_del_servidor(): void
    {
        $organizacion = $this->organization();
        Asset::factory()->count(30)->create([
            'organization_id' => $organizacion->getKey(),
            'catalog_item_id' => null,
        ]);

        $response = $this->actingAs($this->clientUser($organizacion))->get('/activos?per_page=25');
        $pagina = $response->viewData('page')['props']['assets'];

        $this->assertCount(25, $pagina['data']);
        $this->assertSame(30, $pagina['total']);
        $this->assertSame(2, $pagina['last_page']);
    }

    public function test_un_tamano_de_pagina_arbitrario_cae_al_valor_por_defecto(): void
    {
        $organizacion = $this->organization();
        Asset::factory()->count(30)->create([
            'organization_id' => $organizacion->getKey(),
            'catalog_item_id' => null,
        ]);

        // Evita que alguien pida 100.000 filas y tumbe la base.
        $response = $this->actingAs($this->clientUser($organizacion))->get('/activos?per_page=100000');

        $this->assertSame(25, $response->viewData('page')['props']['assets']['per_page']);
    }

    public function test_el_detalle_de_una_pieza_muestra_su_bitacora_completa(): void
    {
        $organizacion = $this->organization();
        $activo = $this->asset($organizacion);

        $this->reportFor($activo, InspectionResult::Apto, inspectedAt: now()->subYear()->toDateString());
        $this->reportFor($activo, InspectionResult::NoAplica, inspectedAt: now()->subMonth()->toDateString());

        $response = $this->actingAs($this->clientUser($organizacion))
            ->get("/activos/{$activo->public_id}");

        $response->assertOk();
        $this->assertCount(2, $response->viewData('page')['props']['history']);
    }

    public function test_consultar_una_pieza_queda_auditado(): void
    {
        $organizacion = $this->organization();
        $activo = $this->asset($organizacion);

        $this->actingAs($this->clientUser($organizacion))->get("/activos/{$activo->public_id}");

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'asset.viewed',
            'subject_id' => $activo->getKey(),
        ]);
    }
}
