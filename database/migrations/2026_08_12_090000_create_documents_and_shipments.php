<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Devoluciones del negocio (12/08/2026):
 *
 *  1. Archivos adjuntos tanto por FMR como por pieza. Se unifican en una sola
 *     tabla polimórfica para que exista UN único camino de subida y descarga:
 *     los documentos son lo más sensible del sistema y multiplicar rutas
 *     multiplicaría la superficie de error.
 *
 *  2. El cliente operador pre-carga los equipos que envía y el taller los
 *     autoriza. Se modela como "envío" con sus líneas; al autorizar se
 *     materializan las recepciones en el taller.
 */
return new class extends Migration
{
    public function up(): void
    {
        // La tabla anterior sólo cubría FMR y nunca llegó a tener datos.
        Schema::dropIfExists('inspection_report_files');

        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();

            /**
             * La organización se guarda aquí y no sólo en el padre: permite
             * aplicar el aislamiento sin recorrer la relación, que es
             * precisamente donde se cuelan los fallos de acceso cruzado.
             */
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            // InspectionReport (informe completo) o Asset (pieza individual).
            $table->morphs('documentable');

            $table->string('category', 40)->default('other')
                ->comment('fmr_report | certificate | photo | datasheet | other');
            $table->string('original_name');
            $table->string('path')->comment('Disco privado; nunca bajo public/');
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('size_bytes');
            $table->string('checksum', 64)->nullable()->comment('sha256, detecta duplicados');
            $table->date('document_date')->nullable();
            $table->string('notes')->nullable();

            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'created_at']);
            $table->index('checksum');
        });

        Schema::create('shipments', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('reference', 40)->comment('Nº de envío visible para el cliente');

            /**
             * draft      → el cliente lo está armando
             * submitted  → enviado, esperando autorización del taller
             * authorized → el taller lo aceptó; se crearon las recepciones
             * rejected   → el taller lo rechazó, con motivo
             */
            $table->string('status', 20)->default('draft')->index();

            $table->date('expected_at')->nullable()->comment('Fecha estimada de llegada al taller');
            $table->text('notes')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'reference']);
            $table->index(['organization_id', 'status']);
        });

        Schema::create('shipment_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipment_id')->constrained()->cascadeOnDelete();

            /**
             * Si la pieza ya está registrada, el cliente la elige y queda
             * enlazada. Si es nueva, la declara por texto y el taller la
             * da de alta al autorizar: el alta de inventario sigue siendo
             * responsabilidad del taller, no del cliente.
             */
            $table->foreignId('asset_id')->nullable()->constrained()->nullOnDelete();
            $table->string('declared_description')->nullable();
            $table->string('declared_serial')->nullable();

            $table->boolean('is_urgent')->default(false);
            $table->string('notes')->nullable();

            // Se rellena al autorizar: enlaza la línea con la recepción creada.
            $table->foreignId('reception_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamps();
            $table->index('shipment_id');
        });

        // Origen de la recepción, para distinguir un ingreso directo del
        // que nació de un envío pre-cargado por el cliente.
        Schema::table('receptions', function (Blueprint $table) {
            $table->foreignId('shipment_id')->nullable()->after('inspection_report_id')
                ->constrained()->nullOnDelete();
        });

        // Notificaciones de Laravel: alertas en la aplicación además del correo.
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
        Schema::table('receptions', fn (Blueprint $table) => $table->dropConstrainedForeignId('shipment_id'));
        Schema::dropIfExists('shipment_items');
        Schema::dropIfExists('shipments');
        Schema::dropIfExists('documents');

        Schema::create('inspection_report_files', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('inspection_report_id')->constrained()->cascadeOnDelete();
            $table->string('category', 40)->default('fmr_report');
            $table->string('original_name');
            $table->string('path');
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('size_bytes');
            $table->string('checksum', 64)->nullable();
            $table->date('document_date')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }
};
