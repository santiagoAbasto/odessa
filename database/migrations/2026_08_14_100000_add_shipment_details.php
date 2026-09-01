<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Datos de cabecera del envío.
 *
 * El negocio pidió que el operador complete la declaración entera al crearla,
 * no sólo la fecha. Lo que el sistema ya sabe —empresa, referencia interna y
 * datos de contacto— se autocompleta; lo que sólo conoce el cliente —su propio
 * remito y el transporte— se pide.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->string('client_reference', 60)->nullable()->after('reference')
                ->comment('Remito o referencia propia del cliente');
            $table->string('contact_name', 120)->nullable()->after('expected_at')
                ->comment('Quién envía; se autocompleta con el usuario');
            $table->string('contact_phone', 40)->nullable()->after('contact_name');
            $table->string('carrier', 120)->nullable()->after('contact_phone')
                ->comment('Transporte o forma de envío');
            $table->string('origin', 160)->nullable()->after('carrier')
                ->comment('Base o yacimiento de origen');

            $table->index(['organization_id', 'client_reference']);
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'client_reference']);
            $table->dropColumn(['client_reference', 'contact_name', 'contact_phone', 'carrier', 'origin']);
        });
    }
};
