<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Operaciones por empresa y pedido por equipo (bloque 2, 27/09/2026).
 *
 * El catálogo de operaciones sigue siendo uno solo —sus reglas, como si
 * dictamina aptitud o cuánto tarda el taller, son de la operación—. Lo que
 * cambia por cliente es cuáles tiene habilitadas y cómo se llaman para él:
 * «quizás para un cliente tenga un nombre y para otro sea otro».
 *
 * El cliente, al armar un envío, indica por cada equipo qué operaciones pide,
 * o describe una operación especial que no está en su lista.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->text('description')->nullable()->after('name')
                ->comment('En qué consiste el trabajo');
        });

        Schema::create('organization_service', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            // Vacíos: vale lo del catálogo.
            $table->string('name', 120)->nullable()->comment('Nombre para este cliente');
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('validity_days')->nullable()
                ->comment('Vigencia para este cliente; null usa la del catálogo');
            $table->timestamps();

            $table->unique(['organization_id', 'service_id']);
        });

        Schema::create('shipment_item_operations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipment_item_id')->constrained()->cascadeOnDelete();
            // Restrict: una operación que ya se pidió no puede desaparecer del
            // catálogo sin que se pierda qué se le pidió al taller.
            $table->foreignId('service_id')->constrained()->restrictOnDelete();

            $table->unique(['shipment_item_id', 'service_id']);
        });

        Schema::table('shipment_items', function (Blueprint $table) {
            $table->text('special_operation')->nullable()->after('notes')
                ->comment('Operación fuera de las habilitadas, descripta por el cliente');
        });
    }

    public function down(): void
    {
        Schema::table('shipment_items', fn (Blueprint $table) => $table->dropColumn('special_operation'));
        Schema::dropIfExists('shipment_item_operations');
        Schema::dropIfExists('organization_service');
        Schema::table('services', fn (Blueprint $table) => $table->dropColumn('description'));
    }
};
