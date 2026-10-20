<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Enlaces del correo de bienvenida, en una tabla propia.
 *
 * Tienen la misma forma que los de recuperación de contraseña, pero NO pueden
 * compartir tabla: la tabla no registra qué tipo de enlace es cada fila, así
 * que un enlace de "olvidé mi contraseña" —que muere en una hora— se podría
 * validar como si fuera de bienvenida y viviría una semana. Separados, un
 * enlace de un tipo directamente no existe para el otro.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('password_invitation_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_invitation_tokens');
    }
};
