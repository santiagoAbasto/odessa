<?php

namespace Tests\Feature;

use App\Models\Reception;
use App\Notifications\AccountRequestReceived;
use App\Notifications\RecertificationDigest;
use App\Notifications\RecertificationDue;
use App\Notifications\ReceptionDeclaredUnrepairable;
use App\Notifications\RepairAuthorizationRequested;
use App\Support\Permissions;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Producción usa la cola `database` y no tiene un proceso escuchándola: lo
 * que se encola sólo corre cuando la tarea programada ejecuta el scheduler.
 *
 * El aviso de la campana no puede depender de eso: se escribe en el momento.
 * El correo sí va por la cola, para no demorar al que aprieta el botón.
 */
class NotificationQueueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        config(['queue.default' => 'database']);
    }

    public function test_el_aviso_de_la_campana_no_espera_a_la_cola_y_el_correo_si(): void
    {
        $org = $this->organization();
        // Sin correo de contacto: así hay un único destinatario de mail.
        $org->forceFill(['contact_email' => null])->save();
        $operador = $this->clientUser($org);
        $recepcion = Reception::create([
            'organization_id' => $org->getKey(),
            'asset_id' => $this->asset($org)->getKey(),
            'received_at' => today(),
        ]);
        $recepcion->forceFill([
            'started_at' => now(), 'released_at' => now(),
            'outcome' => Reception::OUTCOME_REJECTED, 'outcome_notes' => 'Rosca gastada.',
        ])->save();

        $this->actingAs($this->staffUser(Permissions::ROLE_WORKSHOP))
            ->post("/taller/recepciones/{$recepcion->public_id}/consultar-reparacion")
            ->assertRedirect();

        // En la campana ya, sin que nadie procese la cola.
        $this->assertSame(1, $operador->notifications()->count());
        // El correo quedó encolado para la tarea programada.
        $this->assertSame(1, DB::table('jobs')->count());
    }

    public function test_todos_los_avisos_escriben_la_campana_sin_cola(): void
    {
        foreach ([
            AccountRequestReceived::class, RecertificationDigest::class, RecertificationDue::class,
            ReceptionDeclaredUnrepairable::class, RepairAuthorizationRequested::class,
        ] as $aviso) {
            $this->assertSame(['database' => 'sync'], (new \ReflectionClass($aviso))
                ->newInstanceWithoutConstructor()->viaConnections(), $aviso);
        }
    }

    public function test_la_tarea_programada_procesa_la_cola_de_correos(): void
    {
        $comandos = collect(app(Schedule::class)->events())->map(fn ($e) => $e->command ?? '');

        $this->assertTrue($comandos->contains(fn ($c) => str_contains($c, 'queue:work') && str_contains($c, '--stop-when-empty')));
    }
}
