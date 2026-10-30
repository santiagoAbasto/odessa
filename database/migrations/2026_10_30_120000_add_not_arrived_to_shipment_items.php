<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recepción parcial (30/10/2026).
 *
 * Al dar recepción, un equipo declarado puede no haber llegado. Antes el envío
 * era todo o nada: faltaba uno y había que rechazar el envío entero. Ahora el
 * taller recibe lo que llegó y marca lo que no, con su motivo; lo no llegado no
 * entra al taller y queda registrado acá para avisarle al operador.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipment_items', function (Blueprint $table) {
            $table->timestamp('not_arrived_at')->nullable()->after('special_operation')
                ->comment('Marcado como no llegado al dar recepción');
            $table->text('not_arrived_reason')->nullable()->after('not_arrived_at');
        });
    }

    public function down(): void
    {
        Schema::table('shipment_items', function (Blueprint $table) {
            $table->dropColumn(['not_arrived_at', 'not_arrived_reason']);
        });
    }
};
