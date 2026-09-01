<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ventana de recertificación.
 *
 * El negocio pide que un equipo nunca pierda la certificación. Como el taller
 * tarda en trabajarlo, avisar cuando el vencimiento está cerca ya es tarde:
 * hay que avisar cuando se acerca la última fecha en la que el cliente puede
 * despachar el equipo y todavía recibirlo a tiempo.
 *
 *     send_by_at = expires_at − turnaround_days − safety_margin_days
 *
 * `send_by_at` se denormaliza en la pieza, igual que `expires_at` y `status`,
 * porque hay que filtrar y ordenar por ella en listados grandes y calcularla
 * en SQL sería inmanejable.
 *
 * `turnaround_days` va en el ítem de catálogo —el tipo de equipo— porque no
 * todo tarda lo mismo: una válvula no es un preventor. Nulo significa "usá el
 * valor por defecto de la operación".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_items', function (Blueprint $table) {
            $table->unsignedSmallInteger('turnaround_days')->nullable()->after('description')
                ->comment('Días que tarda el taller en trabajar este tipo de equipo');
            $table->unsignedSmallInteger('validity_days')->nullable()->after('turnaround_days')
                ->comment('Vigencia propia del tipo; pisa la del servicio');
            $table->boolean('determines_fitness')->default(true)->after('validity_days')
                ->comment('Si este tipo de equipo recibe dictamen Apto/No Apto');
        });

        Schema::table('assets', function (Blueprint $table) {
            $table->date('send_by_at')->nullable()->after('expires_at')
                ->comment('Última fecha de envío para no perder la certificación');

            // El listado de alertas ordena por esta fecha dentro de cada empresa.
            $table->index(['organization_id', 'send_by_at']);
        });

        /**
         * Avisos ya enviados.
         *
         * Sin esto el sistema le mandaría el mismo correo al cliente todos los
         * días hasta que actúe, y el aviso dejaría de leerse. Se registra qué
         * se avisó, de qué pieza y para qué vencimiento: si la pieza se
         * recertifica y aparece un vencimiento nuevo, vuelve a avisarse.
         */
        Schema::create('recertification_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();

            $table->string('state', 20)->comment('due | missed');
            $table->date('expires_at')->comment('Vencimiento por el que se avisó');
            $table->date('send_by_at');

            $table->timestamp('notified_at');
            $table->string('recipients', 500)->nullable()->comment('A quiénes se les avisó');

            $table->timestamps();

            // Un aviso por pieza, estado y vencimiento: no se repite.
            $table->unique(['asset_id', 'state', 'expires_at'], 'recert_alert_unique');
            $table->index(['organization_id', 'notified_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recertification_alerts');

        Schema::table('assets', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'send_by_at']);
            $table->dropColumn('send_by_at');
        });

        Schema::table('catalog_items', function (Blueprint $table) {
            $table->dropColumn(['turnaround_days', 'validity_days', 'determines_fitness']);
        });
    }
};
