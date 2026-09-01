<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Equipos compuestos.
 *
 * Hay equipos que son un conjunto de piezas y no se despliegan a medias: si un
 * solo componente queda No apto, el conjunto entero está bloqueado. Hasta
 * ahora cada pieza tenía su estado por separado y nadie veía esa dependencia.
 *
 * Se modela como jerarquía sobre la misma tabla —una pieza puede tener un
 * padre— y no como tabla aparte, porque un componente sigue siendo una pieza
 * con su propio historial de inspecciones, su vencimiento y su trazabilidad.
 * Separarlo obligaría a duplicar todo eso.
 *
 * `composite_status` y `composite_expires_at` guardan el estado y el
 * vencimiento efectivos del conjunto —los peores entre los propios y los de
 * sus componentes—. Se denormalizan por la misma razón que `status`: hay que
 * filtrar y ordenar por ellos en listados grandes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->foreignId('parent_id')->nullable()->after('organization_id')
                ->constrained('assets')->nullOnDelete()
                ->comment('Equipo compuesto del que esta pieza es componente');

            $table->string('composite_status', 20)->nullable()->after('status')
                ->comment('Estado efectivo incluyendo los componentes');
            $table->date('composite_expires_at')->nullable()->after('composite_status')
                ->comment('El vencimiento más próximo entre el propio y los de sus componentes');

            $table->index(['organization_id', 'parent_id']);
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'parent_id']);
            $table->dropConstrainedForeignId('parent_id');
            $table->dropColumn(['composite_status', 'composite_expires_at']);
        });
    }
};
