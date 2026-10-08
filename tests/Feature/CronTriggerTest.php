<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Disparador del programador por HTTP.
 *
 * El hosting no tiene cron de consola, así que la tarea programada del panel
 * pide una URL. Esa URL ejecuta todo el programador, de modo que lo único que
 * la separa de internet es la autenticación básica: estas pruebas cuidan ese
 * borde, no el contenido de las tareas.
 */
class CronTriggerTest extends TestCase
{
    private function configureCredentials(string $user = 'cron', string $password = 'secreto-de-prueba'): void
    {
        config(['odessa.cron.user' => $user, 'odessa.cron.password' => $password]);
    }

    public function test_sin_credenciales_configuradas_la_ruta_no_existe(): void
    {
        config(['odessa.cron.user' => null, 'odessa.cron.password' => null]);

        $this->get('/cron')->assertNotFound();
    }

    public function test_con_la_clave_cargada_pero_el_usuario_vacio_tampoco_existe(): void
    {
        config(['odessa.cron.user' => null, 'odessa.cron.password' => 'secreto-de-prueba']);

        $this->get('/cron')->assertNotFound();
    }

    public function test_sin_autenticacion_pide_credenciales(): void
    {
        $this->configureCredentials();

        $this->get('/cron')
            ->assertUnauthorized()
            ->assertHeader('WWW-Authenticate', 'Basic realm="cron"');
    }

    public function test_con_la_clave_equivocada_rechaza(): void
    {
        $this->configureCredentials();

        $this->get('/cron', ['Authorization' => 'Basic '.base64_encode('cron:otra-cosa')])
            ->assertUnauthorized();
    }

    /** El usuario correcto con la clave de otro no alcanza, ni al revés. */
    public function test_con_el_usuario_equivocado_rechaza(): void
    {
        $this->configureCredentials();

        $this->get('/cron', ['Authorization' => 'Basic '.base64_encode('otro:secreto-de-prueba')])
            ->assertUnauthorized();
    }

    public function test_con_las_credenciales_correctas_ejecuta_el_programador(): void
    {
        $this->configureCredentials();

        Artisan::shouldReceive('call')->once()->with('schedule:run');
        Artisan::shouldReceive('output')->once()->andReturn('');

        $this->get('/cron', ['Authorization' => 'Basic '.base64_encode('cron:secreto-de-prueba')])
            ->assertOk()
            ->assertSee('ok');
    }

    /** Un rechazo no debe ejecutar nada: es lo que protege la ruta. */
    public function test_un_rechazo_no_ejecuta_el_programador(): void
    {
        $this->configureCredentials();

        Artisan::shouldReceive('call')->never();

        $this->get('/cron', ['Authorization' => 'Basic '.base64_encode('cron:otra-cosa')])
            ->assertUnauthorized();
    }
}
