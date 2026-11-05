<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cancelar un trabajo en el taller (pedido del 05/11/2026).
 *
 * El cliente avisa por teléfono que ese trabajo no se hace: el equipo pasa
 * directo a «listo para despachar» y vuelve como está, SIN tocar su aptitud.
 * Es un hito más —la etapa se sigue derivando de los hechos— con su motivo y
 * quién lo cargó, que es lo que después explica por qué salió sin trabajarse.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('receptions', function (Blueprint $table) {
            $table->timestamp('cancelled_at')->nullable()->after('unrepairable_notes')
                ->comment('El trabajo se canceló: vuelve al cliente sin tocar su aptitud');
            $table->foreignId('cancelled_by')->nullable()->after('cancelled_at')
                ->constrained('users')->nullOnDelete();
            $table->text('cancel_reason')->nullable()->after('cancelled_by');
        });
    }

    public function down(): void
    {
        Schema::table('receptions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn(['cancelled_at', 'cancel_reason']);
        });
    }
};
