<?php

namespace Tests\Feature;

use App\Actions\RecalculateAssetStatus;
use App\Enums\InspectionResult;
use App\Models\Reception;
use App\Notifications\RecertificationDigest;
use App\Notifications\RecertificationDue;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Avisos de recertificación.
 *
 * Lo que se prueba acá no es que el correo salga, sino que salga al que
 * corresponde, una sola vez, y no cuando no corresponde. Un aviso equivocado
 * o repetido hace que el cliente deje de leerlos, y entonces el sistema deja
 * de servir para lo único que se construyó.
 */
class RecertificationAlertsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();

        config([
            'odessa.recertification.turnaround_days' => 30,
            'odessa.recertification.safety_margin_days' => 15,
            'odessa.recertification.notice_days' => 30,
        ]);

        // Hoy es 1/9: para un vencimiento el 30/10 el límite de envío es el
        // 15/09, o sea dentro de la ventana de aviso de 30 días.
        Carbon::setTestNow('2026-09-01');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function piezaConVencimiento(string $expiresAt, ?string $orgCode = null)
    {
        $organizacion = $this->organization($orgCode);
        $pieza = $this->asset($organizacion);

        $this->reportFor($pieza, InspectionResult::Apto, '2026-01-10', $expiresAt);
        app(RecalculateAssetStatus::class)($pieza);

        return [$organizacion, $pieza->fresh()];
    }

    public function test_avisa_al_cliente_cuando_se_acerca_la_fecha_limite(): void
    {
        Notification::fake();

        [$organizacion] = $this->piezaConVencimiento('2026-10-30');
        $cliente = $this->clientUser($organizacion);

        $this->artisan('odessa:avisar-recertificacion')->assertSuccessful();

        Notification::assertSentTo($cliente, RecertificationDue::class);
    }

    public function test_no_avisa_de_un_equipo_que_todavia_tiene_tiempo_de_sobra(): void
    {
        Notification::fake();

        // Vence en 2027: el límite de envío está a más de un año.
        [$organizacion] = $this->piezaConVencimiento('2027-10-30');
        $cliente = $this->clientUser($organizacion);

        $this->artisan('odessa:avisar-recertificacion')->assertSuccessful();

        Notification::assertNothingSentTo($cliente);
    }

    /**
     * Si el equipo ya está en el taller, pedirle al cliente que lo mande es
     * un aviso equivocado — y de los que hacen perder credibilidad.
     */
    public function test_no_avisa_de_un_equipo_que_ya_esta_en_el_taller(): void
    {
        Notification::fake();

        [$organizacion, $pieza] = $this->piezaConVencimiento('2026-10-30');
        $cliente = $this->clientUser($organizacion);

        Reception::create([
            'organization_id' => $organizacion->getKey(),
            'asset_id' => $pieza->getKey(),
            'received_at' => today()->toDateString(),
        ]);

        $this->artisan('odessa:avisar-recertificacion')->assertSuccessful();

        Notification::assertNothingSentTo($cliente);
    }

    public function test_no_repite_el_aviso_del_mismo_vencimiento(): void
    {
        Notification::fake();

        [$organizacion] = $this->piezaConVencimiento('2026-10-30');
        $cliente = $this->clientUser($organizacion);

        $this->artisan('odessa:avisar-recertificacion')->assertSuccessful();
        $this->artisan('odessa:avisar-recertificacion')->assertSuccessful();

        Notification::assertSentToTimes($cliente, RecertificationDue::class, 1);
    }

    public function test_cada_cliente_recibe_solo_sus_equipos(): void
    {
        Notification::fake();

        [$unaEmpresa] = $this->piezaConVencimiento('2026-10-30', 'UNA');
        [$otraEmpresa] = $this->piezaConVencimiento('2026-10-30', 'OTRA');

        $clienteUno = $this->clientUser($unaEmpresa);
        $clienteDos = $this->clientUser($otraEmpresa);

        $this->artisan('odessa:avisar-recertificacion')->assertSuccessful();

        Notification::assertSentTo($clienteUno, RecertificationDue::class,
            fn (RecertificationDue $n, array $channels, $notifiable) => true);
        Notification::assertSentTo($clienteDos, RecertificationDue::class);

        // Cada uno recibió exactamente un aviso, el suyo.
        Notification::assertSentToTimes($clienteUno, RecertificationDue::class, 1);
        Notification::assertSentToTimes($clienteDos, RecertificationDue::class, 1);
    }

    public function test_el_resumen_semanal_va_al_superadministrador_el_dia_configurado(): void
    {
        Notification::fake();

        // 2026-09-07 es lunes.
        Carbon::setTestNow('2026-09-07');
        config(['odessa.recertification.digest_day' => 1]);

        [$organizacion] = $this->piezaConVencimiento('2026-11-05');
        $this->clientUser($organizacion);
        $admin = $this->staffUser(Permissions::ROLE_SUPER_ADMIN);

        $this->artisan('odessa:avisar-recertificacion')->assertSuccessful();

        Notification::assertSentTo($admin, RecertificationDigest::class);
    }

    public function test_el_resumen_no_sale_los_demas_dias(): void
    {
        Notification::fake();

        // 2026-09-09 es miércoles; el resumen está configurado para el lunes.
        Carbon::setTestNow('2026-09-09');
        config(['odessa.recertification.digest_day' => 1]);

        [$organizacion] = $this->piezaConVencimiento('2026-11-05');
        $this->clientUser($organizacion);
        $admin = $this->staffUser(Permissions::ROLE_SUPER_ADMIN);

        $this->artisan('odessa:avisar-recertificacion')->assertSuccessful();

        Notification::assertNotSentTo($admin, RecertificationDigest::class);
    }

    public function test_el_modo_simulacion_no_envia_nada(): void
    {
        Notification::fake();

        [$organizacion] = $this->piezaConVencimiento('2026-10-30');
        $cliente = $this->clientUser($organizacion);

        $this->artisan('odessa:avisar-recertificacion', ['--dry-run' => true])
            ->assertSuccessful();

        Notification::assertNothingSentTo($cliente);
        $this->assertDatabaseCount('recertification_alerts', 0);
    }

    public function test_el_aviso_queda_registrado_para_no_repetirlo(): void
    {
        Notification::fake();

        [$organizacion, $pieza] = $this->piezaConVencimiento('2026-10-30');
        $this->clientUser($organizacion);

        $this->artisan('odessa:avisar-recertificacion')->assertSuccessful();

        $this->assertDatabaseHas('recertification_alerts', [
            'asset_id' => $pieza->getKey(),
            'state' => 'due',
        ]);
    }
}
