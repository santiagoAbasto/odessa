<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogos compartidos por todo el dominio.
 *
 * Origen en el sistema auditado: los detalles de "Partes de Cliente" exponen
 * `fabricante_id`, `presion_test_id`, `presion_trabajo_id`, `parte_servicio_id`
 * y `conexion_*_id`, lo que confirma que son entidades de catálogo y no texto libre.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('name');
            $table->string('short_code', 20)->unique()->comment('Abreviatura usada en la nomenclatura de FMR');
            $table->string('tax_id')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('contact_phone', 40)->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('manufacturers', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });

        Schema::create('pressure_ratings', function (Blueprint $table) {
            $table->id();
            $table->string('label', 60)->unique()->comment('Ej. 10.000 psi');
            $table->unsignedInteger('psi')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('connections', function (Blueprint $table) {
            $table->id();
            $table->string('label', 120)->unique()->comment('Ej. Brida 4 1/16"-10K BX-153');
            $table->timestamps();
        });

        /**
         * Servicios de inspección. En el sistema auditado aparecen como
         * "CERTIFICACION HAL (OI09)", "OPERACIONAL HAL (OI24)", "MAYOR con COC (OI36)".
         *
         * `determines_fitness` es la regla crítica: sólo algunos servicios emiten
         * un dictamen Apto/No Apto que renueva la vigencia de la pieza. Los demás
         * quedan registrados en la bitácora como "No Aplica".
         */
        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique()->comment('Ej. OI09');
            $table->string('name');
            $table->boolean('determines_fitness')->default(false)->index();
            $table->unsignedSmallInteger('validity_days')->nullable()
                ->comment('Vigencia otorgada; null usa odessa.default_validity_days');
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        /**
         * Catálogo de modelos. Separa el "qué es" del "cuál es".
         * Legacy: tablas `Partes` (descripcion + nro_parte) y `Genericos` (descripcion).
         */
        Schema::create('catalog_items', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 12)->index()->comment('part | generic');
            $table->string('description');
            $table->string('part_number')->nullable()->index();
            $table->foreignId('manufacturer_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['kind', 'description', 'part_number'], 'catalog_items_identity_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_items');
        Schema::dropIfExists('services');
        Schema::dropIfExists('connections');
        Schema::dropIfExists('pressure_ratings');
        Schema::dropIfExists('manufacturers');
        Schema::dropIfExists('organizations');
    }
};
