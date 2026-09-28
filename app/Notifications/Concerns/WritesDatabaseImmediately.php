<?php

namespace App\Notifications\Concerns;

/**
 * El aviso de la campana se escribe en el momento; el correo va por la cola.
 *
 * En producción la cola es `database` y no hay un proceso escuchándola: lo
 * encolado sólo corre cuando la tarea programada ejecuta el scheduler. Si el
 * aviso de la aplicación también esperara ahí, la campana quedaría vacía
 * mientras la tarea no esté configurada —y es justamente la vía que queda
 * cuando el correo no sale—.
 */
trait WritesDatabaseImmediately
{
    /** @return array<string, string> */
    public function viaConnections(): array
    {
        return ['database' => 'sync'];
    }
}
