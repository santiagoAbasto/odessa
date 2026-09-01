<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La reparación la decide el cliente, no el taller.
 *
 * El flujo que faltaba, tal como lo dibujó el negocio: entre el rechazo y la
 * reparación hay una consulta al cliente, y de su respuesta salen dos caminos
 * distintos.
 *
 *   Rechazada → A reparar ─┬─ SÍ  → Reparada → Despachada
 *     (se consulta)        │
 *                          └─ NO  → Despachada sin reparar (queda no apta)
 *
 * Hasta ahora `sendToRepair()` lo resolvía el taller por su cuenta y esa
 * decisión no quedaba en ningún lado. Dos consecuencias, las dos malas: no
 * había con qué respaldar el cobro de la reparación, y una recepción rechazada
 * cuyo cliente no autorizaba nada quedaba trabada para siempre, porque
 * `isDispatchable()` la bloqueaba y no existía ninguna otra salida.
 *
 * La respuesta puede llegar por el panel o por fuera (teléfono, correo). Por
 * eso se guarda el canal y se admite adjuntar evidencia: si después hay que
 * cobrar la reparación, la autorización tiene que poder mostrarse.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('receptions', function (Blueprint $table) {
            // --- La consulta ---------------------------------------------
            $table->timestamp('repair_requested_at')->nullable()->after('outcome_notes')
                ->comment('Cuándo se le pidió al cliente que autorice la reparación');
            $table->foreignId('repair_requested_by')->nullable()->after('repair_requested_at')
                ->constrained('users')->nullOnDelete();
            $table->text('repair_request_notes')->nullable()->after('repair_requested_by')
                ->comment('Qué se propone reparar y con qué costo estimado');

            // --- La respuesta ---------------------------------------------
            $table->string('repair_decision', 12)->nullable()->after('repair_request_notes')
                ->comment('approved | declined');
            $table->timestamp('repair_decided_at')->nullable()->after('repair_decision');
            // Quién dejó registrada la respuesta: puede ser el propio cliente
            // desde su panel, o alguien de Odessa cargando lo que recibió.
            $table->foreignId('repair_decided_by')->nullable()->after('repair_decided_at')
                ->constrained('users')->nullOnDelete();
            $table->string('repair_decision_channel', 16)->nullable()->after('repair_decided_by')
                ->comment('panel | email | phone | whatsapp | in_person');
            $table->text('repair_decision_notes')->nullable()->after('repair_decision_channel');

            // Para listar rápido lo que está esperando respuesta del cliente,
            // que es la cola que traba el taller.
            $table->index(['organization_id', 'repair_decision']);
        });
    }

    public function down(): void
    {
        Schema::table('receptions', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'repair_decision']);
            $table->dropConstrainedForeignId('repair_requested_by');
            $table->dropConstrainedForeignId('repair_decided_by');
            $table->dropColumn([
                'repair_requested_at', 'repair_request_notes',
                'repair_decision', 'repair_decided_at',
                'repair_decision_channel', 'repair_decision_notes',
            ]);
        });
    }
};
