<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * El comando de puesta en producción. Sólo se prueba el camino --dry-run con
 * JSON, que es el que se corre en el servidor (la consola no tiene ext-zip y
 * no puede leer .xlsx). La carga real no se prueba acá: borra y escribe, y su
 * lógica vive en ImportarInventario, que tiene su propia prueba.
 */
class CargarProduccionTest extends TestCase
{
    public function test_dry_run_lee_el_json_y_no_toca_la_base(): void
    {
        $json = tempnam(sys_get_temp_dir(), 'hasa').'.json';
        file_put_contents($json, json_encode([
            ['HASA', 'REF-1', 'SERIE-A', 'L-100', 'ELMAR', 'desc', 'SB / GIE', '', 'ELMAR', '0', '0', '0', '0', '10M', '15M', '2026-09-05', '', ''],
            ['HASA', 'REF-2', 'SERIE-B', 'L-100', 'ELMAR', 'desc', 'SB / GIE', '', 'ELMAR', '0', '0', '0', '0', '10M', '15M', '', '', ''],
        ]));

        $this->artisan('odessa:cargar-produccion', ['archivo' => $json, '--dry-run' => true])
            ->expectsOutputToContain('Leídos: 2 equipos (1 con COS)')
            ->assertSuccessful();

        @unlink($json);
    }
}
