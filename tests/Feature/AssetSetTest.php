<?php

namespace Tests\Feature;

use App\Actions\RecalculateAssetStatus;
use App\Enums\AssetStatus;
use App\Enums\InspectionResult;
use App\Models\ActivityLog;
use App\Models\Asset;
use App\Models\AssetSet;
use App\Models\Organization;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sets de equipos: se arman desde el alta y se les tildan las piezas.
 *
 * Pedido de Daniel (04/11/2026): una casilla «esto será un set de equipos» al
 * crear el equipo, tildar después las piezas que lo componen, que «Forma parte
 * de» ofrezca sólo sets y no todos los equipos, y que «Sets de equipos» los
 * muestre.
 */
class AssetSetTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->org = $this->organization();
    }

    private function taller(): User
    {
        return $this->staffUser(Permissions::ROLE_WORKSHOP);
    }

    private function set(array $atributos = [], ?Organization $org = null): Asset
    {
        return $this->asset($org ?? $this->org, ['kind' => Asset::KIND_GENERIC, 'is_set' => true, 'description' => 'Set de prueba', ...$atributos]);
    }

    private function pieza(array $atributos = [], ?Organization $org = null): Asset
    {
        return $this->asset($org ?? $this->org, ['kind' => Asset::KIND_PART, ...$atributos]);
    }

    /** @return list<string> */
    private function candidatas(Asset $set, string $q = '', ?User $usuario = null): array
    {
        return collect(
            $this->actingAs($usuario ?? $this->taller())
                ->getJson("/activos/{$set->public_id}/piezas-disponibles?q=".urlencode($q))
                ->assertOk()
                ->json('options')
        )->pluck('id')->all();
    }

    // --- Alta con la casilla ------------------------------------------------------

    public function test_la_casilla_crea_un_set_y_lleva_a_su_ficha_para_tildar_piezas(): void
    {
        $this->actingAs($this->taller())->post('/activos', [
            'organization_public_id' => $this->org->public_id,
            'kind' => Asset::KIND_GENERIC,
            'description' => 'Set 1 n°2',
            'is_set' => true,
        ]);

        $set = Asset::where('description', 'Set 1 n°2')->firstOrFail();

        $this->assertTrue($set->is_set);
        $this->assertSame(0, $set->components()->count(), 'Un set recién creado nace vacío y existe igual.');
        $this->get('/activos/'.$set->public_id)->assertOk();
    }

    public function test_el_alta_de_un_set_redirige_a_su_ficha(): void
    {
        $respuesta = $this->actingAs($this->taller())->post('/activos', [
            'organization_public_id' => $this->org->public_id,
            'kind' => Asset::KIND_GENERIC,
            'description' => 'Set con ficha',
            'is_set' => true,
        ]);

        $respuesta->assertRedirect('/activos/'.Asset::where('description', 'Set con ficha')->value('public_id'));
    }

    public function test_sin_la_casilla_no_es_un_set(): void
    {
        $this->actingAs($this->taller())->post('/activos', [
            'organization_public_id' => $this->org->public_id,
            'kind' => Asset::KIND_GENERIC,
            'description' => 'Equipo común',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertFalse(Asset::where('description', 'Equipo común')->firstOrFail()->is_set);
    }

    // --- Piezas que se pueden tildar ----------------------------------------------

    public function test_ofrece_solo_piezas_libres_de_la_misma_empresa_y_nunca_sets(): void
    {
        $set = $this->set();
        $libre = $this->pieza(['description' => 'Libre']);
        $otroSet = $this->set(['description' => 'Otro set']);
        $yaAsignada = $this->pieza(['description' => 'Asignada']);
        $yaAsignada->forceFill(['parent_id' => $otroSet->getKey()])->save();
        $ajena = $this->pieza(['description' => 'Ajena'], $this->organization());

        $ids = $this->candidatas($set);

        $this->assertSame([$libre->public_id], $ids);
        $this->assertNotContains($ajena->public_id, $ids);
        $this->assertNotContains($set->public_id, $ids);
        $this->assertNotContains($otroSet->public_id, $ids);
        $this->assertNotContains($yaAsignada->public_id, $ids);
    }

    public function test_las_piezas_se_buscan_por_texto_y_vienen_acotadas(): void
    {
        $set = $this->set();
        foreach (range(1, 40) as $n) {
            $this->pieza(['description' => sprintf('Pieza %03d', $n), 'serial_number' => "SN-{$n}"]);
        }
        $buscada = $this->pieza(['description' => 'Rara', 'serial_number' => 'SERIE-BUSCADA-9']);

        $this->assertCount(20, $this->candidatas($set));
        $this->assertSame([$buscada->public_id], $this->candidatas($set, 'SERIE-BUSCADA'));
    }

    public function test_un_equipo_que_no_es_set_no_ofrece_piezas(): void
    {
        $comun = $this->pieza();

        $this->actingAs($this->taller())
            ->getJson("/activos/{$comun->public_id}/piezas-disponibles")
            ->assertNotFound();
    }

    // --- Agregar y quitar ---------------------------------------------------------

    public function test_agregar_piezas_las_cuelga_del_set_y_el_set_toma_su_estado(): void
    {
        $set = $this->set();
        $sana = $this->pieza();
        $rota = $this->pieza();
        $this->reportFor($sana, InspectionResult::Apto, now()->subMonth()->toDateString(), now()->addYear()->toDateString());
        $this->reportFor($rota, InspectionResult::NoApto, now()->subMonth()->toDateString());
        app(RecalculateAssetStatus::class)($sana);
        app(RecalculateAssetStatus::class)($rota);

        $this->actingAs($this->taller())
            ->post("/activos/{$set->public_id}/componentes", ['piece_public_ids' => [$sana->public_id, $rota->public_id]])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame($set->getKey(), $sana->fresh()->parent_id);
        $this->assertSame($set->getKey(), $rota->fresh()->parent_id);
        // No se despliega a medias: una pieza no apta bloquea al set entero.
        $this->assertSame(AssetStatus::Unfit, $set->fresh()->effectiveStatus());
    }

    public function test_solo_se_agregan_piezas_validas_y_sin_ninguna_valida_se_avisa(): void
    {
        $set = $this->set();
        $valida = $this->pieza();
        $ajena = $this->pieza([], $this->organization());
        $otroSet = $this->set(['description' => 'Otro']);
        $asignada = $this->pieza();
        $asignada->forceFill(['parent_id' => $otroSet->getKey()])->save();

        $this->actingAs($this->taller())
            ->post("/activos/{$set->public_id}/componentes", [
                'piece_public_ids' => [$valida->public_id, $ajena->public_id, $otroSet->public_id, $asignada->public_id],
            ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame($set->getKey(), $valida->fresh()->parent_id);
        $this->assertNull($ajena->fresh()->parent_id);
        $this->assertNull($otroSet->fresh()->parent_id);
        $this->assertSame($otroSet->getKey(), $asignada->fresh()->parent_id);

        $this->post("/activos/{$set->public_id}/componentes", ['piece_public_ids' => [$ajena->public_id]])
            ->assertSessionHasErrors('piece_public_ids');
    }

    public function test_quitar_una_pieza_la_suelta_y_el_set_se_recalcula(): void
    {
        $set = $this->set();
        $rota = $this->pieza();
        $rota->forceFill(['parent_id' => $set->getKey()])->save();
        $this->reportFor($rota, InspectionResult::NoApto, now()->subMonth()->toDateString());
        app(RecalculateAssetStatus::class)($rota);
        $this->assertSame(AssetStatus::Unfit, $set->fresh()->effectiveStatus());

        $this->actingAs($this->taller())
            ->delete("/activos/{$set->public_id}/componentes/{$rota->public_id}")
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertNull($rota->fresh()->parent_id);
        $this->assertNotSame(AssetStatus::Unfit, $set->fresh()->effectiveStatus());
    }

    public function test_no_se_quita_una_pieza_que_no_es_de_ese_set(): void
    {
        $set = $this->set();
        $otroSet = $this->set(['description' => 'Otro']);
        $pieza = $this->pieza();
        $pieza->forceFill(['parent_id' => $otroSet->getKey()])->save();

        $this->actingAs($this->taller())
            ->delete("/activos/{$set->public_id}/componentes/{$pieza->public_id}")
            ->assertNotFound();

        $this->assertSame($otroSet->getKey(), $pieza->fresh()->parent_id);
    }

    public function test_armar_un_set_queda_en_auditoria(): void
    {
        $set = $this->set();
        $pieza = $this->pieza(['description' => 'Cabezal']);

        $this->actingAs($this->taller())
            ->post("/activos/{$set->public_id}/componentes", ['piece_public_ids' => [$pieza->public_id]])
            ->assertRedirect();
        $this->delete("/activos/{$set->public_id}/componentes/{$pieza->public_id}")->assertRedirect();

        $this->assertSame(1, ActivityLog::where('action', 'asset.components_added')->count());
        $this->assertSame(1, ActivityLog::where('action', 'asset.component_removed')->count());
        $this->assertStringContainsString('Cabezal', ActivityLog::where('action', 'asset.components_added')->value('description').json_encode(ActivityLog::where('action', 'asset.components_added')->value('changes')));
    }

    public function test_un_cliente_no_arma_sets(): void
    {
        $set = $this->set();
        $pieza = $this->pieza();
        $cliente = $this->clientUser($this->org, Permissions::ROLE_CLIENT_OPERATOR);

        $this->actingAs($cliente)->getJson("/activos/{$set->public_id}/piezas-disponibles")->assertForbidden();
        $this->actingAs($cliente)->post("/activos/{$set->public_id}/componentes", ['piece_public_ids' => [$pieza->public_id]])->assertForbidden();

        $this->assertNull($pieza->fresh()->parent_id);
    }

    // --- Estado de un set --------------------------------------------------------

    /** Una pieza con un dictamen final, ya recalculada. */
    private function pieza_con(InspectionResult $resultado, ?string $vence = null): Asset
    {
        $pieza = $this->pieza();
        $this->reportFor($pieza, $resultado, now()->subMonth()->toDateString(), $vence);
        app(RecalculateAssetStatus::class)($pieza);

        return $pieza;
    }

    private function colgar(Asset $set, Asset ...$piezas): void
    {
        foreach ($piezas as $pieza) {
            $pieza->forceFill(['parent_id' => $set->getKey()])->save();
        }
        app(RecalculateAssetStatus::class)($set->refresh());
    }

    /**
     * Un set no se inspecciona como tal: se arma con piezas que sí. Si su propio
     * «sin inspección» pesara, un set con todas las piezas aptas figuraría para
     * siempre como «sin inspección» y nunca estaría listo.
     */
    public function test_un_set_sin_dictamen_propio_toma_el_estado_de_sus_piezas(): void
    {
        $set = $this->set();
        $this->colgar(
            $set,
            $this->pieza_con(InspectionResult::Apto, now()->addMonths(10)->toDateString()),
            $this->pieza_con(InspectionResult::Apto, now()->addMonths(3)->toDateString()),
        );

        $set = $set->fresh();
        $this->assertSame(AssetStatus::Fit, $set->effectiveStatus());
        $this->assertSame(now()->addMonths(3)->toDateString(), $set->effectiveExpiresAt()->toDateString(), 'Vence cuando vence su pieza más próxima.');
    }

    public function test_un_set_con_una_pieza_no_apta_queda_no_apto(): void
    {
        $set = $this->set();
        $this->colgar($set, $this->pieza_con(InspectionResult::Apto, now()->addYear()->toDateString()), $this->pieza_con(InspectionResult::NoApto));

        $this->assertSame(AssetStatus::Unfit, $set->fresh()->effectiveStatus());
    }

    public function test_un_set_con_una_pieza_sin_inspeccion_no_esta_listo(): void
    {
        $set = $this->set();
        $this->colgar($set, $this->pieza_con(InspectionResult::Apto, now()->addYear()->toDateString()), $this->pieza());

        $this->assertSame(AssetStatus::Unknown, $set->fresh()->effectiveStatus());
    }

    public function test_un_set_con_dictamen_propio_sigue_contando_su_propio_estado(): void
    {
        $set = $this->set();
        $this->reportFor($set, InspectionResult::NoApto, now()->subMonth()->toDateString());
        $this->colgar($set, $this->pieza_con(InspectionResult::Apto, now()->addYear()->toDateString()));

        $this->assertSame(AssetStatus::Unfit, $set->fresh()->effectiveStatus());
    }

    // --- «Forma parte de» ofrece sólo sets ---------------------------------------

    public function test_forma_parte_de_acepta_un_set_y_rechaza_un_equipo_cualquiera(): void
    {
        $pieza = $this->pieza();
        $set = $this->set();
        $comun = $this->pieza(['description' => 'Equipo común']);

        $this->actingAs($this->taller())
            ->put("/activos/{$pieza->public_id}", ['description' => 'Componente', 'parent_public_id' => $comun->public_id])
            ->assertSessionHasErrors('parent_public_id');
        $this->assertNull($pieza->fresh()->parent_id);

        $this->put("/activos/{$pieza->public_id}", ['description' => 'Componente', 'parent_public_id' => $set->public_id])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($set->getKey(), $pieza->fresh()->parent_id);
    }

    public function test_un_set_no_puede_colgar_de_uno_de_sus_propios_componentes(): void
    {
        $abuelo = $this->set(['description' => 'Abuelo']);
        $hijo = $this->set(['description' => 'Hijo']);
        $hijo->forceFill(['parent_id' => $abuelo->getKey()])->save();

        $this->actingAs($this->taller())
            ->put("/activos/{$abuelo->public_id}", ['description' => 'Abuelo', 'parent_public_id' => $hijo->public_id])
            ->assertSessionHasErrors('parent_public_id');

        $this->assertNull($abuelo->fresh()->parent_id);
    }

    public function test_un_set_con_piezas_no_puede_dejar_de_serlo(): void
    {
        $set = $this->set();
        $this->pieza()->forceFill(['parent_id' => $set->getKey()])->save();

        $this->actingAs($this->taller())
            ->put("/activos/{$set->public_id}", ['description' => 'Set de prueba', 'is_set' => false])
            ->assertSessionHasErrors('is_set');

        $this->assertTrue($set->fresh()->is_set);
    }

    public function test_un_equipo_comun_se_puede_convertir_en_set(): void
    {
        $comun = $this->asset($this->org, ['kind' => Asset::KIND_GENERIC]);

        $this->actingAs($this->taller())
            ->put("/activos/{$comun->public_id}", ['description' => 'Ahora set', 'is_set' => true])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertTrue($comun->fresh()->is_set);
    }

    // --- La pantalla de Sets ------------------------------------------------------

    private function setsListados(?User $usuario = null, string $query = ''): array
    {
        return collect(
            $this->actingAs($usuario ?? $this->taller())->get('/sets'.$query)->viewData('page')['props']['sets']['data']
        )->pluck('id')->all();
    }

    public function test_la_pantalla_de_sets_lista_los_sets_y_no_todos_los_equipos(): void
    {
        $set = $this->set();
        $this->pieza(['description' => 'Pieza suelta']);
        $this->asset($this->org, ['kind' => Asset::KIND_GENERIC, 'description' => 'Equipo que no es set']);

        $this->assertSame([$set->public_id], $this->setsListados());
    }

    public function test_el_set_muestra_cuantas_piezas_tiene_y_su_estado(): void
    {
        $set = $this->set();
        foreach (range(1, 3) as $n) {
            $this->pieza()->forceFill(['parent_id' => $set->getKey()])->save();
        }

        $fila = $this->actingAs($this->taller())->get('/sets')->viewData('page')['props']['sets']['data'][0];

        $this->assertSame(3, $fila['assetsCount']);
        $this->assertSame('/activos/'.$set->public_id, $fila['href']);
    }

    public function test_un_cliente_ve_solo_los_sets_de_su_empresa(): void
    {
        $propio = $this->set(['description' => 'Set propio']);
        $this->set(['description' => 'Set ajeno'], $this->organization());

        $this->assertSame([$propio->public_id], $this->setsListados($this->clientUser($this->org, Permissions::ROLE_CLIENT_VIEWER)));
    }

    public function test_se_encuentra_el_set_por_la_serie_de_una_de_sus_piezas(): void
    {
        $set = $this->set(['description' => 'Con la pieza']);
        $this->pieza(['serial_number' => 'SERIE-DENTRO-77'])->forceFill(['parent_id' => $set->getKey()])->save();
        $this->set(['description' => 'Sin la pieza']);

        $this->assertSame([$set->public_id], $this->setsListados(null, '?serial=SERIE-DENTRO'));
    }

    public function test_los_sets_del_sistema_anterior_siguen_visibles(): void
    {
        AssetSet::create(['organization_id' => $this->org->getKey(), 'name' => 'Set viejo']);

        $props = $this->actingAs($this->taller())->get('/sets')->viewData('page')['props'];

        $this->assertCount(1, $props['legacySets']);
        $this->assertSame('Set viejo', $props['legacySets'][0]['name']);
    }
}
