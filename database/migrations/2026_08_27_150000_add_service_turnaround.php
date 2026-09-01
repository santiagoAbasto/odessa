<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tiempo de cada operación del taller.
 *
 * El negocio pidió poder ver y cambiar cuánto tarda cada operación, porque no
 * todas llevan lo mismo: una inspección visual no es una prueba hidrostática.
 * Ese tiempo es el que alimenta la fecha límite de envío, así que tenerlo
 * editable evita que la regla quede clavada en el código.
 *
 * Nulo significa "usá el valor por defecto de la operación", que es lo
 * razonable para las que nunca se midieron.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->unsignedSmallInteger('turnaround_days')->nullable()->after('validity_days')
                ->comment('Días que lleva esta operación de punta a punta');
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn('turnaround_days');
        });
    }
};
