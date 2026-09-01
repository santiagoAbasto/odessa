<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->uuid('public_id')->nullable()->unique()->after('id');
            /**
             * null  => usuario interno de Odessa Oil (taller/administración), ve todas las organizaciones
             *          según sus permisos.
             * valor => usuario de cliente, confinado a esa organización por el global scope.
             */
            $table->foreignId('organization_id')->nullable()->after('public_id')
                ->constrained()->nullOnDelete();
            $table->string('phone', 40)->nullable()->after('email');
            $table->string('job_title')->nullable()->after('phone');
            $table->boolean('is_active')->default(true)->index()->after('job_title');
            $table->timestamp('last_login_at')->nullable()->after('is_active');
            $table->string('last_login_ip', 45)->nullable()->after('last_login_at');
            $table->text('two_factor_secret')->nullable()->after('last_login_ip');
            $table->text('two_factor_recovery_codes')->nullable()->after('two_factor_secret');
            $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_recovery_codes');
            $table->softDeletes();

            $table->index(['organization_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'is_active']);
            $table->dropConstrainedForeignId('organization_id');
            $table->dropColumn([
                'public_id', 'phone', 'job_title', 'is_active', 'last_login_at', 'last_login_ip',
                'two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at', 'deleted_at',
            ]);
        });
    }
};
