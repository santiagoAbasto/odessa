<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// El cron de cPanel ejecuta schedule:run. Estos trabajos eliminan tokens
// vencidos y limitan el crecimiento de la tabla de fallos de cola.
Schedule::command('auth:clear-resets')->dailyAt('02:10');
Schedule::command('queue:prune-failed --hours=168')->dailyAt('02:20');

/*
 * Correos encolados (avisos de reparación, recertificación, solicitudes).
 *
 * En el hosting no hay un proceso escuchando la cola: la vacía la tarea
 * programada, cada minuto, y termina cuando no queda nada. Sin la tarea
 * cargada en el panel, los correos se acumulan en la tabla `jobs`. El aviso
 * de la campana no depende de esto: se escribe en el momento.
 */
Schedule::command('queue:work --stop-when-empty --tries=3 --max-time=50')
    ->everyMinute()
    ->withoutOverlapping();

/*
 * Avisos de recertificación.
 *
 * A diario y temprano, para que el aviso esté en la bandeja del cliente al
 * empezar la jornada. El resumen semanal para Odessa lo decide el propio
 * comando según el día configurado, así que basta con una sola entrada.
 *
 * `withoutOverlapping` porque con muchas empresas el envío puede pasarse de
 * la hora, y dos corridas simultáneas duplicarían correos.
 */
Schedule::command('odessa:avisar-recertificacion')
    ->dailyAt('07:00')
    ->withoutOverlapping();
