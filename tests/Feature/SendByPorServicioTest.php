<?php

namespace Tests\Feature;

use App\Actions\RecalculateAssetStatus;
use App\Enums\InspectionResult;
use App\Models\Asset;
use App\Models\CatalogItem;
use App\Models\Service;
use App\Support\AssetCertificationSummary;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * «Mandar antes de» según los servicios del equipo (pedido del 04/11/2026).
 *
 * Cada certificación (COS, COC…) resta a su vencimiento los días de taller de SU
 * servicio y el margen; el equipo toma la fecha más próxima. Lo guardado en la
 * pieza, la planilla y los avisos tienen que dar la misma fecha.
 */
class SendByPorServicioTest extends TestCase
{
    use RefreshDatabase;

    private Service $cos;

    private Service $coc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();

        config([
            'odessa.recertification.turnaround_days' => 30,
            'odessa.recertification.safety_margin_days' => 15,
        ]);

        $this->cos = Service::create(['code' => 'COS', 'name' => 'Certificación anual', 'determines_fitness' => true, 'validity_days' => 365, 'turnaround_days' => 20]);
        $this->coc = Service::create(['code' => 'COC', 'name' => 'Conformidad', 'determines_fitness' => true, 'validity_days' => 1825, 'turnaround_days' => 90]);
    }

    /** Una certificación final de ese servicio, ya recalculada. */
    private function certificar(Asset $activo, Service $servicio, int $venceEnDias): void
    {
        $informe = $this->reportFor($activo, InspectionResult::Apto, now()->subDays(10)->toDateString(), now()->addDays($venceEnDias)->toDateString());
        $informe->forceFill(['service_id' => $servicio->getKey()])->save();
        $informe->lines()->update(['service_id' => $servicio->getKey()]);

        app(RecalculateAssetStatus::class)($activo);
    }

    private function dia(int $enDias): string
    {
        return now()->addDays($enDias)->startOfDay()->toDateString();
    }

    public function test_cada_servicio_resta_sus_propios_dias_y_vale_la_fecha_mas_proxima(): void
    {
        $activo = $this->asset($this->organization());

        // COS vence antes (día 100) pero tarda 20: límite = 100 − 20 − 15 = día 65.
        $this->certificar($activo, $this->cos, 100);
        // COC vence después (día 130) pero tarda 90: límite = 130 − 90 − 15 = día 25.
        $this->certificar($activo, $this->coc, 130);

        $activo->refresh();

        // El vencimiento del equipo sigue siendo el que vence primero…
        $this->assertSame($this->dia(100), $activo->expires_at->toDateString());
        // …pero hay que mandarlo antes por el servicio que más tarda.
        $this->assertSame($this->dia(25), $activo->send_by_at->toDateString());
    }

    public function test_sin_dias_en_el_servicio_rigen_los_del_tipo_y_despues_el_valor_por_defecto(): void
    {
        $sinDias = Service::create(['code' => 'VIS', 'name' => 'Sin tiempo cargado', 'determines_fitness' => true, 'validity_days' => 365]);
        $tipo = CatalogItem::create(['kind' => Asset::KIND_PART, 'description' => 'Preventor', 'turnaround_days' => 60]);
        $organizacion = $this->organization();

        // Servicio sin días + tipo con 60: 100 − 60 − 15 = día 25.
        $conTipo = $this->asset($organizacion, ['catalog_item_id' => $tipo->getKey()]);
        $this->certificar($conTipo, $sinDias, 100);
        $this->assertSame($this->dia(25), $conTipo->fresh()->send_by_at->toDateString());

        // Servicio sin días y sin tipo: valor por defecto, 100 − 30 − 15 = día 55.
        $sinNada = $this->asset($organizacion);
        $this->certificar($sinNada, $sinDias, 100);
        $this->assertSame($this->dia(55), $sinNada->fresh()->send_by_at->toDateString());

        // Los días del servicio mandan sobre los del tipo: COS (20) pese al tipo (60): 100 − 20 − 15 = día 65.
        $ambos = $this->asset($organizacion, ['catalog_item_id' => $tipo->getKey()]);
        $this->certificar($ambos, $this->cos, 100);
        $this->assertSame($this->dia(65), $ambos->fresh()->send_by_at->toDateString());
    }

    public function test_la_planilla_y_lo_guardado_dan_la_misma_fecha(): void
    {
        $tipo = CatalogItem::create(['kind' => Asset::KIND_PART, 'description' => 'Válvula', 'turnaround_days' => 45]);
        $activo = $this->asset($this->organization(), ['catalog_item_id' => $tipo->getKey()]);

        $this->certificar($activo, $this->cos, 100);
        $this->certificar($activo, $this->coc, 130);

        $activo->load('catalogItem');
        $resumen = (new AssetCertificationSummary)->forAssets([$activo])[$activo->getKey()];

        $this->assertSame($this->dia(25), $activo->fresh()->send_by_at->toDateString());
        $this->assertSame($activo->fresh()->send_by_at->toDateString(), $resumen['nextSendBy']->toDateString());
    }

    public function test_los_avisos_leen_la_misma_fecha_que_la_pantalla(): void
    {
        $activo = $this->asset($this->organization());

        $this->certificar($activo, $this->cos, 100);
        $this->certificar($activo, $this->coc, 130);

        $activo->refresh();

        $this->assertSame($activo->send_by_at->toDateString(), $activo->recertificationWindow()->sendBy()->toDateString());
    }

    public function test_un_set_se_acota_por_su_componente_mas_apurado(): void
    {
        $organizacion = $this->organization();
        $set = $this->asset($organizacion, ['is_set' => true]);
        $pieza = $this->asset($organizacion, ['parent_id' => $set->getKey()]);

        $this->certificar($pieza, $this->coc, 130);

        $set->load('catalogItem');
        $resumen = (new AssetCertificationSummary)->forAssets([$set])[$set->getKey()];

        // El set no tiene dictamen propio: su plazo es el de la pieza (130 − 90 − 15 = día 25).
        $this->assertSame($this->dia(25), $resumen['nextSendBy']->toDateString());
    }

    public function test_cambiar_los_dias_de_un_servicio_recalcula_las_piezas(): void
    {
        $activo = $this->asset($this->organization());
        $this->certificar($activo, $this->coc, 130);
        $this->assertSame($this->dia(25), $activo->fresh()->send_by_at->toDateString());

        $this->actingAs($this->staffUser(Permissions::ROLE_SUPER_ADMIN))
            ->put("/administracion/operaciones/{$this->coc->getKey()}", [
                'name' => 'Conformidad',
                'code' => 'COC',
                'determines_fitness' => true,
                'validity_days' => 1825,
                'turnaround_days' => 40,
                'is_active' => true,
            ])
            ->assertRedirect();

        // 130 − 40 − 15 = día 75.
        $this->assertSame($this->dia(75), $activo->fresh()->send_by_at->toDateString());
    }

    public function test_el_comando_rehace_la_fecha_guardada_y_avisa_si_la_operacion_no_existe(): void
    {
        $activo = $this->asset($this->organization());
        $this->certificar($activo, $this->coc, 130);

        // Una fecha vieja, de cuando regía el tipo de equipo: se simula pisándola.
        Asset::withoutGlobalScopes()->whereKey($activo->getKey())->update(['send_by_at' => $this->dia(85)]);

        $this->artisan('odessa:recalcular-fechas-envio')
            ->expectsOutputToContain('Piezas recalculadas: 1')
            ->assertSuccessful();

        $this->assertSame($this->dia(25), $activo->fresh()->send_by_at->toDateString());

        $this->artisan('odessa:recalcular-fechas-envio --servicio=NOEXISTE')->assertFailed();
    }
}
