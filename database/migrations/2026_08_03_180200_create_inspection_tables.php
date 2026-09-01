<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FMR (Field Maintenance Report) — el documento central del negocio.
 *
 * Un FMR agrupa N activos. Para CADA activo registra un resultado propio
 * (apto / no_apto / no_aplica / sin_procesar), su servicio y su vencimiento.
 * Esto se observó en el detalle de una parte: un mismo FMR aparece con
 * "Estado en FMR" distinto según la pieza.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inspection_reports', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('number', 30)->comment('Nº FMR; puede llevar sufijo de revisión, ej. 3231A');
            $table->foreignId('asset_set_id')->nullable()->constrained()->nullOnDelete()->comment('Equipo/Set inspeccionado');
            $table->string('equipment_label')->nullable()->comment('Equipo en texto cuando no hay Set');
            $table->date('inspected_at')->index();
            $table->string('status', 20)->default('draft')->index()
                ->comment('draft | preliminary | final | cancelled');
            $table->foreignId('service_id')->nullable()->constrained()->nullOnDelete();
            $table->text('notes')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('finalized_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('finalized_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'number']);
            $table->index(['organization_id', 'inspected_at']);
        });

        Schema::create('inspection_report_asset', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inspection_report_id')->constrained()->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->nullable()->constrained()->nullOnDelete();
            $table->string('result', 16)->default('sin_procesar')->index()
                ->comment('apto | no_apto | no_aplica | sin_procesar');
            $table->date('expires_at')->nullable()->index();
            $table->string('seal', 60)->nullable()->comment('Precinto — legacy ClientesGenericosVencimientos.precinto');
            $table->text('findings')->nullable();
            $table->timestamps();

            $table->unique(['inspection_report_id', 'asset_id']);
            $table->index(['asset_id', 'result']);
        });

        Schema::create('inspection_report_files', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('inspection_report_id')->constrained()->cascadeOnDelete();
            $table->string('category', 40)->default('fmr_report')->comment('fmr_report | certificate | photo | other');
            $table->string('original_name');
            $table->string('path')->comment('Ruta en disco privado; nunca bajo public/');
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('size_bytes');
            $table->string('checksum', 64)->nullable()->comment('sha256 para detectar duplicados/corrupción');
            $table->date('document_date')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index('inspection_report_id', 'irf_report_idx');
        });

        // FK diferida: assets apunta al FMR vigente, pero assets se crea antes.
        Schema::table('assets', function (Blueprint $table) {
            $table->foreign('current_inspection_report_id')
                ->references('id')->on('inspection_reports')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropForeign(['current_inspection_report_id']);
        });
        Schema::dropIfExists('inspection_report_files');
        Schema::dropIfExists('inspection_report_asset');
        Schema::dropIfExists('inspection_reports');
    }
};
