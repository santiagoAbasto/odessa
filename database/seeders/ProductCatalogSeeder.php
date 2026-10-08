<?php

namespace Database\Seeders;

use App\Models\CatalogItem;
use App\Models\Manufacturer;
use Illuminate\Database\Seeder;

/**
 * Catálogo real de productos de Odessa.
 *
 * Sale de la hoja «Datos Lista» de la plantilla de carga masiva que mandó el
 * negocio el 30/09/2026: son las descripciones de la lista desplegable con la
 * que clasifican cada equipo, y los fabricantes que usan.
 *
 * Va aparte de CatalogSeeder a propósito. Aquél sólo siembra estructura con
 * nombres inventados; esto son datos reales del negocio y conviene poder
 * correrlo —o no— por separado.
 *
 * Es idempotente: se puede correr las veces que haga falta y no duplica ni
 * pisa lo que ya esté cargado. No borra nada, así que las correcciones hechas
 * desde la pantalla de Productos sobreviven a una nueva corrida.
 *
 *     php artisan db:seed --class=ProductCatalogSeeder --force
 *
 * Las descripciones van TEXTUALES, tal como las escribieron. Si alguna está
 * mal (hay al menos una, «PANEL DE SEGURDAD»), se corrige desde la pantalla y
 * no acá: así queda registro de quién la cambió y cuándo.
 */
class ProductCatalogSeeder extends Seeder
{
    /** Fabricantes de la columna OEM de la plantilla. */
    private const MANUFACTURERS = [
        'ELMAR', 'HUNTING', 'GCM', 'NXL / LEE SPECIALTIES', 'RMZ',
    ];

    /** Columna «Descripcion Odessa», en el mismo orden del archivo. */
    private const PRODUCTS = [
        'SB / GIE',
        'STRIPPER DOBLE',
        'STRIPPER TRIPLE',
        'X-OVER QUICK UNION',
        'X-OVER ROSCADO',
        'VENT VALVE ELMAR',
        'CABLE CUTTER',
        'BCV',
        'LUBRICADOR',
        'TOOL TRAP',
        'PUMP IN SUB',
        'PLUG VALVE',
        'TEE',
        'SENSOR',
        'SINGLE WIRELINE VALVE',
        'DUAL WIRELINE VALVE',
        'TRIPLE WIRELINE VALVE',
        'QUAD WIRELINE VALVE',
        'DESCONECTOR REMOTO',
        'BRIDA QUICK UNION',
        'BRIDA DESCONECTOR REMOTO',
        'XOVER BRIDA',
        'PANEL',
        'FILTRO DE AIRE',
        'PANEL DE SEGURDAD',
        'QUICK TEST SUB',
        'SPOOL ADAPTER',
        'BLIND FLANGE',
        'SINGLE PACKOFF',
        'DUAL PACKOFF',
        'TOOL CATCHER',
        'QUICK UNION TEST PLUG',
        'QUICK UNION TEST CAP',
        'QUICK UNION LIFT/TEST CAP',
        'QUICK UNION LIFTING BAIL',
        'STUFFING BOX',
        'PLUG',
        'PRESSURE TEST UNIT',
        'CAGE SINGLE WLV',
        'CAGE DUAL WLV',
        'CAGE TRIPLE WLV',
        'CAGE QUAD WLV',
        'SAFETY FLOW DIVERTER',
        'CHEMICAL INJECTION SUB',
        'QUICK LATCH',
        'ROLDANA',
        'SPREADER BAR',
        'SETTING TOOL',
        'MANOMETRO',
        'VALVULA MANUAL',
    ];

    public function run(): void
    {
        foreach (self::MANUFACTURERS as $name) {
            Manufacturer::firstOrCreate(['name' => $name]);
        }

        foreach (self::PRODUCTS as $description) {
            /*
             * El número de parte queda vacío: la plantilla lo lleva por equipo,
             * no por producto. Si el negocio define que el catálogo también
             * tiene el suyo, se completa desde la pantalla.
             */
            CatalogItem::firstOrCreate([
                'kind' => 'part',
                'description' => $description,
                'part_number' => null,
            ]);
        }

        $this->command?->info(count(self::PRODUCTS).' productos y '.count(self::MANUFACTURERS).' fabricantes verificados.');
    }
}
