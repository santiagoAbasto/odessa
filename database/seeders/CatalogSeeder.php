<?php

namespace Database\Seeders;

use App\Models\Connection;
use App\Models\Manufacturer;
use App\Models\PressureRating;
use App\Models\Service;
use Illuminate\Database\Seeder;

/**
 * Catálogos base.
 *
 * Los servicios reproducen la ESTRUCTURA observada en el sistema auditado
 * (código + nombre + si emite dictamen), con nombres genéricos. No se copia
 * ningún dato real de clientes.
 */
class CatalogSeeder extends Seeder
{
    public function run(): void
    {
        $services = [
            ['code' => 'OI09', 'name' => 'Certificación', 'determines_fitness' => true, 'validity_days' => 365],
            ['code' => 'OI24', 'name' => 'Operacional', 'determines_fitness' => false, 'validity_days' => null],
            ['code' => 'OI36', 'name' => 'Mantenimiento mayor con certificado', 'determines_fitness' => true, 'validity_days' => 365],
            ['code' => 'OI12', 'name' => 'Verificación dimensional', 'determines_fitness' => true, 'validity_days' => 730],
            ['code' => 'OI40', 'name' => 'Reparación', 'determines_fitness' => false, 'validity_days' => null],
        ];

        foreach ($services as $service) {
            Service::updateOrCreate(['code' => $service['code']], $service);
        }

        foreach (['Fabricante Norte', 'Industrias Sur', 'Equipos Centro', 'Metalúrgica Andina'] as $name) {
            Manufacturer::firstOrCreate(['name' => $name]);
        }

        foreach ([['5.000 psi', 5000], ['10.000 psi', 10000], ['15.000 psi', 15000], ['20.000 psi', 20000]] as [$label, $psi]) {
            PressureRating::firstOrCreate(['label' => $label], ['psi' => $psi]);
        }

        $connections = [
            'Brida 4 1/16" - 10K - BX153', 'Brida 5 1/8" - 10K - BX169',
            'Brida 7 1/16" - 10K - BX156', 'Unión rápida 2"', 'Rosca NPT 2"',
        ];

        foreach ($connections as $label) {
            Connection::firstOrCreate(['label' => $label]);
        }
    }
}
