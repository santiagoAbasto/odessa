<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La columna `kpk_code` pasa a llamarse `code`.
 *
 * El sistema es de Odessa Oil: el nombre de la empresa cuyo sistema se auditó
 * no tiene por qué seguir en el esquema. En pantalla la etiqueta ya era
 * "Código" desde hace tiempo; esto alinea la base con lo que se ve.
 *
 * Es condicional a propósito. La migración que crea la tabla ya nombra la
 * columna `code`, así que una instalación nueva no tiene nada que renombrar;
 * en producción, donde aquella migración corrió cuando la columna todavía se
 * llamaba `kpk_code`, este paso hace el cambio. Sin la condición, una de las
 * dos situaciones fallaría.
 *
 * Es un renombre, no una recreación: los datos quedan intactos.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('assets', 'kpk_code')) {
            return;
        }

        Schema::table('assets', function (Blueprint $table) {
            $table->renameColumn('kpk_code', 'code');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('assets', 'code')) {
            return;
        }

        Schema::table('assets', function (Blueprint $table) {
            $table->renameColumn('code', 'kpk_code');
        });
    }
};
