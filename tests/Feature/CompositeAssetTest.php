<?php

namespace Tests\Feature;

use App\Actions\RecalculateAssetStatus;
use App\Enums\AssetStatus;
use App\Enums\InspectionResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Equipos compuestos.
 *
 * Regla del negocio: un conjunto no se despliega a medias. Si un solo
 * componente queda No apto, el conjunto entero está bloqueado, aunque el
 * conjunto en sí tenga su propia certificación al día.
 */
class CompositeAssetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    private function recalcular($asset): void
    {
        app(RecalculateAssetStatus::class)($asset);
    }

    public function test_un_componente_no_apto_bloquea_el_conjunto(): void
    {
        $organizacion = $this->organization();

        $conjunto = $this->asset($organizacion, ['description' => 'Preventor completo']);
        $sano = $this->asset($organizacion, ['parent_id' => $conjunto->getKey()]);
        $roto = $this->asset($organizacion, ['parent_id' => $conjunto->getKey()]);

        $this->reportFor($conjunto, InspectionResult::Apto, '2026-01-10', '2027-01-10');
        $this->reportFor($sano, InspectionResult::Apto, '2026-01-10', '2027-01-10');
        $this->reportFor($roto, InspectionResult::NoApto, '2026-01-10');

        $this->recalcular($conjunto);
        $this->recalcular($sano);
        $this->recalcular($roto);

        $conjunto->refresh();

        // El conjunto tiene su propia certificación al día...
        $this->assertSame(AssetStatus::Fit, $conjunto->status);
        // ...pero no se puede desplegar, porque una de sus piezas no sirve.
        $this->assertSame(AssetStatus::Unfit, $conjunto->effectiveStatus());
    }

    public function test_el_conjunto_dice_cual_de_sus_piezas_lo_bloquea(): void
    {
        $organizacion = $this->organization();

        $conjunto = $this->asset($organizacion);
        $sano = $this->asset($organizacion, [
            'parent_id' => $conjunto->getKey(),
            'description' => 'Válvula sana',
        ]);
        $roto = $this->asset($organizacion, [
            'parent_id' => $conjunto->getKey(),
            'description' => 'Sello dañado',
        ]);

        $this->reportFor($sano, InspectionResult::Apto, '2026-01-10', '2027-01-10');
        $this->reportFor($roto, InspectionResult::NoApto, '2026-01-10');

        $this->recalcular($sano);
        $this->recalcular($roto);

        $bloqueantes = $conjunto->fresh()->load('components')->blockingComponents();

        $this->assertCount(1, $bloqueantes);
        $this->assertSame('Sello dañado', $bloqueantes->first()->description);
    }

    /** Reparar la pieza tiene que desbloquear el conjunto solo. */
    public function test_al_recuperarse_el_componente_el_conjunto_se_libera(): void
    {
        $organizacion = $this->organization();

        $conjunto = $this->asset($organizacion);
        $componente = $this->asset($organizacion, ['parent_id' => $conjunto->getKey()]);

        $this->reportFor($conjunto, InspectionResult::Apto, '2026-01-10', '2027-01-10');
        $this->reportFor($componente, InspectionResult::NoApto, '2026-01-10');

        $this->recalcular($conjunto);
        $this->recalcular($componente);

        $this->assertSame(AssetStatus::Unfit, $conjunto->fresh()->effectiveStatus());

        // Nueva inspección, más reciente, que lo aprueba.
        $this->reportFor($componente, InspectionResult::Apto, '2026-06-10', '2027-06-10');
        $this->recalcular($componente);

        $this->assertSame(AssetStatus::Fit, $conjunto->fresh()->effectiveStatus());
    }

    /**
     * Recalcular un componente tiene que arrastrar a su conjunto sin que
     * nadie lo pida: si no, el bloqueo aparecería recién la próxima vez que
     * alguien tocara el conjunto.
     */
    public function test_el_recalculo_de_un_componente_arrastra_al_conjunto(): void
    {
        $organizacion = $this->organization();

        $conjunto = $this->asset($organizacion);
        $componente = $this->asset($organizacion, ['parent_id' => $conjunto->getKey()]);

        // Punto de partida: conjunto y componente aprobados.
        $this->reportFor($conjunto, InspectionResult::Apto, '2026-01-10', '2027-01-10');
        $this->reportFor($componente, InspectionResult::Apto, '2026-01-10', '2027-01-10');

        $this->recalcular($conjunto);
        $this->recalcular($componente);

        $this->assertSame(AssetStatus::Fit, $conjunto->fresh()->effectiveStatus());

        // El componente se rechaza en una inspección posterior.
        $this->reportFor($componente, InspectionResult::NoApto, '2026-02-10');

        // Se recalcula SÓLO el componente: el conjunto debe enterarse solo.
        $this->recalcular($componente);

        $this->assertSame(AssetStatus::Unfit, $conjunto->fresh()->effectiveStatus());
    }

    /** El conjunto vence cuando vence su primera pieza. */
    public function test_el_vencimiento_del_conjunto_es_el_mas_proximo(): void
    {
        $organizacion = $this->organization();

        $conjunto = $this->asset($organizacion);
        $tarde = $this->asset($organizacion, ['parent_id' => $conjunto->getKey()]);
        $temprano = $this->asset($organizacion, ['parent_id' => $conjunto->getKey()]);

        $this->reportFor($conjunto, InspectionResult::Apto, '2026-01-10', '2027-12-01');
        $this->reportFor($tarde, InspectionResult::Apto, '2026-01-10', '2027-10-01');
        $this->reportFor($temprano, InspectionResult::Apto, '2026-01-10', '2027-03-15');

        $this->recalcular($conjunto);
        $this->recalcular($tarde);
        $this->recalcular($temprano);

        $this->assertSame(
            '2027-03-15',
            $conjunto->fresh()->effectiveExpiresAt()->toDateString()
        );
    }

    /**
     * Un componente que nunca se inspeccionó tampoco permite afirmar que el
     * conjunto esté apto: pesa más que "Apto" a propósito.
     */
    public function test_un_componente_sin_inspeccion_impide_declarar_apto_al_conjunto(): void
    {
        $organizacion = $this->organization();

        $conjunto = $this->asset($organizacion);
        $sinInspeccion = $this->asset($organizacion, ['parent_id' => $conjunto->getKey()]);

        $this->reportFor($conjunto, InspectionResult::Apto, '2026-01-10', '2027-01-10');

        $this->recalcular($conjunto);
        $this->recalcular($sinInspeccion);

        $this->assertSame(AssetStatus::Unknown, $conjunto->fresh()->effectiveStatus());
    }

    /** Una pieza suelta no debe quedar marcada como compuesta. */
    public function test_una_pieza_sin_componentes_no_tiene_estado_compuesto(): void
    {
        $pieza = $this->asset($this->organization());

        $this->reportFor($pieza, InspectionResult::Apto, '2026-01-10', '2027-01-10');
        $this->recalcular($pieza);

        $fresca = $pieza->fresh();

        $this->assertNull($fresca->composite_status);
        $this->assertSame(AssetStatus::Fit, $fresca->effectiveStatus());
        $this->assertFalse($fresca->isComposite());
    }

    /** Conjuntos anidados: el bloqueo tiene que subir más de un nivel. */
    public function test_el_bloqueo_sube_por_conjuntos_anidados(): void
    {
        $organizacion = $this->organization();

        $abuelo = $this->asset($organizacion);
        $padre = $this->asset($organizacion, ['parent_id' => $abuelo->getKey()]);
        $nieto = $this->asset($organizacion, ['parent_id' => $padre->getKey()]);

        foreach ([$abuelo, $padre] as $apto) {
            $this->reportFor($apto, InspectionResult::Apto, '2026-01-10', '2027-01-10');
            $this->recalcular($apto);
        }

        $this->reportFor($nieto, InspectionResult::NoApto, '2026-02-10');
        $this->recalcular($nieto);

        $this->assertSame(AssetStatus::Unfit, $padre->fresh()->effectiveStatus());
        $this->assertSame(AssetStatus::Unfit, $abuelo->fresh()->effectiveStatus());
    }
}
