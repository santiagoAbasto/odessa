<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Certificaciones que un equipo ya traía de OTRA empresa (pedido del 05/11/2026).
 *
 * En la primera carga del inventario muchos equipos ya están certificados por
 * quien los certificó antes. Odessa no emitió ese informe, así que no se crea
 * uno: se carga a mano en la ficha del equipo, con su operación, su empresa y
 * sus fechas, para que el cliente tenga trazabilidad de vencimientos. Cuando
 * Odessa certifica la misma operación, su informe es el que rige.
 *
 * Una por equipo y operación: cargar otra vez la misma la edita.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_external_certifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->constrained()->restrictOnDelete();
            $table->string('certified_by', 160)->comment('Empresa que certificó');
            $table->string('certificate_number', 120)->nullable();
            $table->date('certified_at')->comment('Cuándo se certificó');
            $table->date('expires_at')->comment('Hasta cuándo vale');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['asset_id', 'service_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_external_certifications');
    }
};
