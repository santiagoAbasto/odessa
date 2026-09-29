<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // El negocio pidió poder cargar varios correos por empresa para las
        // comunicaciones masivas. Se guardan en la misma columna, uno por
        // línea, así que hace falta más de un VARCHAR corto.
        Schema::table('organizations', function (Blueprint $table) {
            $table->text('contact_email')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->string('contact_email')->nullable()->change();
        });
    }
};
