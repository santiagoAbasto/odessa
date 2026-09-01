<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Activos del cliente (piezas físicas individuales) y sus agrupaciones (Sets).
 *
 * Legacy: `ClientesPartes` + `ClientesGenericos`. Ambas comparten ciclo de vida,
 * bitácora de FMR y estado de aptitud, por lo que se unifican en `assets`
 * discriminadas por `kind`. Las columnas específicas de cada tipo quedan
 * nullable y documentadas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assets', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique()->comment('Identificador expuesto en URLs; evita IDOR por enumeración');
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('catalog_item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 12)->comment('part | generic');

            // Identidad física
            $table->string('serial_number')->nullable();
            $table->string('code', 32)->nullable()->comment('Código interno grabado en la pieza');
            $table->string('client_reference')->nullable()->comment('Ref. del cliente en su propio sistema');
            $table->string('manufacturer_reference')->nullable();
            $table->string('description')->nullable();
            $table->string('workshop_description')->nullable()->comment('Legacy: clientes_parte.descripcion_taller');

            // Específico de genéricos
            $table->string('brand')->nullable();
            $table->string('model')->nullable();
            $table->string('identifier')->nullable()->comment('ID alterno de genéricos');

            // Específico de partes (ficha técnica)
            $table->foreignId('manufacturer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('test_pressure_id')->nullable()->constrained('pressure_ratings')->nullOnDelete();
            $table->foreignId('working_pressure_id')->nullable()->constrained('pressure_ratings')->nullOnDelete();
            $table->foreignId('service_id')->nullable()->constrained()->nullOnDelete()->comment('Servicio habitual de la pieza');
            $table->foreignId('connection_top_id')->nullable()->constrained('connections')->nullOnDelete();
            $table->foreignId('connection_bottom_id')->nullable()->constrained('connections')->nullOnDelete();
            $table->foreignId('connection_side_1_id')->nullable()->constrained('connections')->nullOnDelete();
            $table->foreignId('connection_side_2_id')->nullable()->constrained('connections')->nullOnDelete();

            /**
             * Estado derivado y desnormalizado para poder filtrar/ordenar sin
             * subconsultas. La fuente de verdad es `inspection_report_asset`;
             * estas tres columnas las recalcula RecalculateAssetStatus.
             */
            $table->string('status', 12)->default('unknown')->comment('fit | expiring | unfit | unknown');
            $table->date('expires_at')->nullable();
            $table->foreignId('current_inspection_report_id')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'expires_at']);
            $table->index(['organization_id', 'kind']);
            $table->index('serial_number');
            $table->index('code');
            $table->unique(['organization_id', 'kind', 'serial_number'], 'assets_org_serial_unique');
        });

        Schema::create('asset_sets', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['organization_id', 'name']);
        });

        Schema::create('asset_set_asset', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_set_id')->constrained()->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
            $table->unique(['asset_set_id', 'asset_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_set_asset');
        Schema::dropIfExists('asset_sets');
        Schema::dropIfExists('assets');
    }
};
