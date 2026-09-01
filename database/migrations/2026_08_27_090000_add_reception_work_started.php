<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Distingue "recibido pero sin empezar" de "en proceso".
 *
 * Hasta ahora la etapa se deducía de dos marcas de tiempo —liberado y
 * despachado—, así que todo lo que estaba en el taller caía en una sola
 * bolsa. El negocio necesita ver cuáles ya se están trabajando y cuáles
 * están esperando, que es lo que dice si el taller va al día o acumula cola.
 *
 * Se resuelve con una marca más, no con una columna de estado: el estado
 * sigue derivándose de los hechos registrados, que es lo que impide que la
 * etiqueta y la realidad se separen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('receptions', function (Blueprint $table) {
            $table->timestamp('started_at')->nullable()->after('is_urgent')
                ->comment('Cuándo el taller empezó a trabajar el equipo');
            $table->foreignId('started_by')->nullable()->after('started_at')
                ->constrained('users')->nullOnDelete();

            $table->index(['organization_id', 'started_at']);
        });

        /*
         * Las recepciones que ya tienen informe de inspección estaban siendo
         * trabajadas: sin esto quedarían todas como "pendientes" y el primer
         * tablero que vea el taller estaría mintiendo.
         */
        DB::table('receptions')
            ->whereNull('started_at')
            ->where(function ($q) {
                $q->whereNotNull('inspection_report_id')
                    ->orWhereNotNull('released_at');
            })
            ->update(['started_at' => DB::raw('received_at')]);
    }

    public function down(): void
    {
        Schema::table('receptions', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'started_at']);
            $table->dropConstrainedForeignId('started_by');
            $table->dropColumn('started_at');
        });
    }
};
