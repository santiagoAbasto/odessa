<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recepción de equipos (área Taller).
 *
 * Legacy `/recepcion-equipos`: una fila = una pieza recibida bajo un FMR.
 * Hitos observados y sus filtros: urgente, liberado (0/1), despachado (0/1).
 * "Pendiente/Procesado" se calcula contra `fecha_prometida`, no es una columna.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receptions', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained()->restrictOnDelete();
            $table->foreignId('inspection_report_id')->nullable()->constrained()->nullOnDelete();

            $table->date('received_at')->index();
            $table->date('promised_at')->nullable()->index();
            $table->boolean('is_urgent')->default(false)->index();

            $table->timestamp('released_at')->nullable()->index();
            $table->foreignId('released_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('dispatched_at')->nullable()->index();
            $table->foreignId('dispatched_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('dispatch_note')->nullable()->comment('Remito / guía de despacho');

            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'dispatched_at']);
            $table->index(['organization_id', 'released_at', 'dispatched_at'], 'receptions_pipeline_idx');
        });

        /**
         * Bitácora de comentarios sobre cualquier entidad (parte, FMR, recepción).
         * `is_internal` oculta notas de taller al cliente.
         */
        Schema::create('comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->morphs('commentable');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('body');
            $table->boolean('is_internal')->default(false)->index();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('account_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('email');
            $table->string('name');
            $table->string('phone', 40)->nullable();
            $table->string('company');
            $table->text('message')->nullable();
            $table->string('status', 20)->default('pending')->index()->comment('pending | approved | rejected');
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });

        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 60)->index();
            $table->nullableMorphs('subject');
            $table->string('description')->nullable();
            $table->json('changes')->nullable()->comment('{"old":{},"new":{}} — sin datos sensibles');
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->timestamp('created_at')->useCurrent()->index();

            $table->index(['organization_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
        Schema::dropIfExists('account_requests');
        Schema::dropIfExists('comments');
        Schema::dropIfExists('receptions');
    }
};
