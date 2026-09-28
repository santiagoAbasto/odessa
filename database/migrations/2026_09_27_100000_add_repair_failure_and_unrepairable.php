<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dos salidas nuevas del circuito de rechazo, pedidas por el negocio el 27/09/2026:
 *
 *  - la reparación también se dictamina: si no dio resultado se rechaza y la
 *    pieza vuelve a Rechazadas. Se intenta una sola vez;
 *  - el taller puede determinar que una pieza rechazada no tiene solución y
 *    devolverla sin consultar al cliente.
 *
 * Son hitos y no una columna de estado: la etapa se sigue derivando de los
 * hechos registrados.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('receptions', function (Blueprint $table) {
            $table->timestamp('repair_failed_at')->nullable()->after('repaired_at')
                ->comment('La reparación se rechazó al dictaminarla');
            $table->foreignId('repair_failed_by')->nullable()->after('repair_failed_at')
                ->constrained('users')->nullOnDelete();
            $table->text('repair_failure_notes')->nullable()->after('repair_failed_by');

            $table->timestamp('unrepairable_at')->nullable()->after('repair_failure_notes')
                ->comment('El taller determinó que no tiene solución');
            $table->foreignId('unrepairable_by')->nullable()->after('unrepairable_at')
                ->constrained('users')->nullOnDelete();
            $table->text('unrepairable_notes')->nullable()->after('unrepairable_by');
        });
    }

    public function down(): void
    {
        Schema::table('receptions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('repair_failed_by');
            $table->dropConstrainedForeignId('unrepairable_by');
            $table->dropColumn(['repair_failed_at', 'repair_failure_notes', 'unrepairable_at', 'unrepairable_notes']);
        });
    }
};
