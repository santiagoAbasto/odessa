<?php

namespace Tests\Feature;

use App\Actions\RecalculateAssetStatus;
use App\Enums\InspectionResult;
use App\Models\CatalogItem;
use App\Support\RecertificationWindow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Ventana de recertificación.
 *
 * La regla que sostiene todo esto es que el equipo nunca puede perder la
 * certificación. Como el taller tarda, avisar cuando el vencimiento está
 * cerca ya es tarde: hay que avisar antes de la última fecha en la que el
 * cliente todavía puede despachar el equipo.
 */
class RecertificationWindowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    /**
     * El caso textual que planteó el negocio: certificación que vence el 30
     * de octubre, un mes de taller y quince días de margen obligan a
     * despachar antes del 15 de septiembre.
     */
    public function test_el_ejemplo_del_negocio_da_el_15_de_septiembre(): void
    {
        $ventana = new RecertificationWindow(
            expiresAt: Carbon::parse('2026-10-30'),
            turnaroundDays: 30,
            safetyMarginDays: 15,
        );

        $this->assertSame('2026-09-15', $ventana->sendBy()->toDateString());
        $this->assertSame(45, $ventana->leadTimeDays());
    }

    public function test_sin_vencimiento_no_hay_nada_que_calcular(): void
    {
        $ventana = new RecertificationWindow(null, 30, 15);

        $this->assertNull($ventana->sendBy());
        $this->assertNull($ventana->daysToSendBy());
        $this->assertSame(RecertificationWindow::STATE_UNKNOWN, $ventana->state());
    }

    public function test_los_tres_estados_de_la_ventana(): void
    {
        $hoy = Carbon::parse('2026-09-01');

        // Vence el 30/10; con 45 días de anticipación, el límite es el 15/09.
        $ventana = new RecertificationWindow(Carbon::parse('2026-10-30'), 30, 15);

        // Faltan 14 días para el límite: dentro de la ventana de aviso.
        $this->assertSame(
            RecertificationWindow::STATE_DUE,
            $ventana->state(noticeDays: 30, today: $hoy)
        );

        // Dos meses antes: todavía sobra tiempo.
        $this->assertSame(
            RecertificationWindow::STATE_OK,
            $ventana->state(noticeDays: 30, today: Carbon::parse('2026-07-01'))
        );

        // Pasado el 15/09 ya no llega, aunque la certificación siga vigente.
        $this->assertSame(
            RecertificationWindow::STATE_MISSED,
            $ventana->state(noticeDays: 30, today: Carbon::parse('2026-09-20'))
        );
    }

    /**
     * Éste es el punto que el negocio remarcó: el 20 de septiembre el equipo
     * SIGUE certificado —vence el 30 de octubre— pero ya no llega a tiempo.
     * Un sistema que sólo mire el vencimiento no dice nada y el cliente se
     * entera cuando ya lo perdió.
     */
    public function test_un_equipo_certificado_puede_estar_fuera_de_plazo(): void
    {
        $hoy = Carbon::parse('2026-09-20');
        $vence = Carbon::parse('2026-10-30');

        $ventana = new RecertificationWindow($vence, 30, 15);

        $this->assertTrue($vence->isAfter($hoy), 'La certificación sigue vigente.');
        $this->assertSame(RecertificationWindow::STATE_MISSED, $ventana->state(30, $hoy));
        $this->assertStringContainsString('perderá la certificación', $ventana->clientMessage($hoy));
    }

    public function test_el_aviso_al_cliente_dice_la_fecha_y_el_motivo(): void
    {
        $ventana = new RecertificationWindow(Carbon::parse('2026-10-30'), 30, 15);

        $mensaje = $ventana->clientMessage(Carbon::parse('2026-09-01'));

        $this->assertStringContainsString('30/10/2026', $mensaje);
        $this->assertStringContainsString('15/09/2026', $mensaje);
        $this->assertStringContainsString('30 días', $mensaje);
    }

    public function test_el_tipo_de_equipo_puede_tener_su_propio_tiempo_de_reparacion(): void
    {
        config([
            'odessa.recertification.turnaround_days' => 30,
            'odessa.recertification.safety_margin_days' => 15,
        ]);

        $organizacion = $this->organization();

        // Un preventor tarda el doble que el valor por defecto.
        $tipo = CatalogItem::create([
            'kind' => 'part',
            'description' => 'Preventor de reventones',
            'turnaround_days' => 60,
        ]);

        $pieza = $this->asset($organizacion, ['catalog_item_id' => $tipo->getKey()]);

        $this->reportFor($pieza, InspectionResult::Apto, '2026-01-10', '2026-10-30');
        app(RecalculateAssetStatus::class)($pieza);

        // 30/10 − 60 − 15 = 16/08, bastante antes que el 15/09 por defecto.
        $this->assertSame('2026-08-16', $pieza->fresh()->send_by_at->toDateString());
    }

    public function test_el_recalculo_deja_la_fecha_limite_en_la_pieza(): void
    {
        config([
            'odessa.recertification.turnaround_days' => 30,
            'odessa.recertification.safety_margin_days' => 15,
        ]);

        $pieza = $this->asset($this->organization());

        $this->reportFor($pieza, InspectionResult::Apto, '2026-01-10', '2026-10-30');
        app(RecalculateAssetStatus::class)($pieza);

        $this->assertSame('2026-09-15', $pieza->fresh()->send_by_at->toDateString());
    }

    public function test_una_pieza_sin_dictamen_no_tiene_fecha_limite(): void
    {
        $pieza = $this->asset($this->organization());

        app(RecalculateAssetStatus::class)($pieza);

        $this->assertNull($pieza->fresh()->send_by_at);
    }
}
