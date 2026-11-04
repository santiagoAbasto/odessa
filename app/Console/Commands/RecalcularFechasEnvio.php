<?php

namespace App\Console\Commands;

use App\Actions\RecalculateServiceAssets;
use App\Models\Service;
use Illuminate\Console\Command;

/**
 * Recalcula estado, vencimiento y «mandar antes de» de las piezas con informes
 * finales.
 *
 * Se corre una vez al desplegar el cambio del 04/11/2026 (la fecha límite pasó a
 * depender de los días de taller de cada servicio) y cuando haga falta rehacer lo
 * guardado. No toca otra cosa que lo derivado de las inspecciones.
 */
class RecalcularFechasEnvio extends Command
{
    protected $signature = 'odessa:recalcular-fechas-envio
                            {--servicio= : Código de una operación (COS, COC…); sin él, todas}';

    protected $description = 'Recalcula la fecha límite de envío de las piezas según los días de taller de cada servicio';

    public function handle(RecalculateServiceAssets $recalcular): int
    {
        $servicio = null;

        if ($codigo = $this->option('servicio')) {
            $servicio = Service::where('code', $codigo)->first();

            if ($servicio === null) {
                $this->error("No existe la operación «{$codigo}».");

                return self::FAILURE;
            }
        }

        $hechas = $recalcular($servicio);

        $this->info("Piezas recalculadas: {$hechas}.");

        return self::SUCCESS;
    }
}
