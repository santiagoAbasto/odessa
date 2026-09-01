<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ciclo completo del equipo en el taller.
 *
 * El cliente corrigió el flujo: terminar de trabajar un equipo no es un solo
 * final, es un dictamen. Puede aprobarse —y entonces sale— o rechazarse, y en
 * ese caso todavía le queda la reparación antes de poder despacharse:
 *
 *   Pendiente → En proceso → Aprobada ─────────────────────→ Despachada
 *                          ↘ Rechazada → A reparar → Reparada ↗
 *
 * Antes había un único hito "liberada" que mezclaba las dos salidas, así que
 * un equipo rechazado y uno aprobado se veían igual en la lista.
 *
 * Se sigue derivando el estado de los hechos registrados y no de una columna
 * de estado: la etiqueta nunca puede contradecir lo que pasó.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('receptions', function (Blueprint $table) {
            // Dictamen del trabajo. Acompaña a `released_at`, que pasa a ser
            // "cuándo se resolvió", no "cuándo se aprobó".
            $table->string('outcome', 12)->nullable()->after('released_by')
                ->comment('approved | rejected');
            $table->text('outcome_notes')->nullable()->after('outcome')
                ->comment('Motivo del rechazo o nota de la aprobación');

            $table->timestamp('repair_started_at')->nullable()->after('outcome_notes')
                ->comment('Cuándo pasó a reparación tras ser rechazada');
            $table->foreignId('repair_started_by')->nullable()->after('repair_started_at')
                ->constrained('users')->nullOnDelete();

            $table->timestamp('repaired_at')->nullable()->after('repair_started_by');
            $table->foreignId('repaired_by')->nullable()->after('repaired_at')
                ->constrained('users')->nullOnDelete();

            $table->index(['organization_id', 'outcome']);
        });

        /*
         * Lo ya liberado se considera aprobado: hasta ahora liberar significaba
         * exactamente eso. Sin este relleno, todo el histórico quedaría sin
         * dictamen y aparecería como si nunca se hubiera resuelto.
         */
        DB::table('receptions')
            ->whereNotNull('released_at')
            ->whereNull('outcome')
            ->update(['outcome' => 'approved']);
    }

    public function down(): void
    {
        Schema::table('receptions', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'outcome']);
            $table->dropConstrainedForeignId('repair_started_by');
            $table->dropConstrainedForeignId('repaired_by');
            $table->dropColumn(['outcome', 'outcome_notes', 'repair_started_at', 'repaired_at']);
        });
    }
};
