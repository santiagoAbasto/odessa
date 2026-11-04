<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Un equipo puede ser un SET: un conjunto de piezas que se arma desde el alta.
 *
 * Pedido de Daniel (04/11/2026): al crear un equipo, una casilla «esto será un
 * set de equipos»; después se le van tildando las piezas que lo componen, y la
 * pantalla «Sets de equipos» tiene que mostrarlo.
 *
 * Hasta ahora «conjunto» era sólo una consecuencia —un equipo que tiene
 * componentes (`parent_id`)—, y por eso un set recién creado, todavía vacío,
 * no existía como tal. Hace falta marcarlo de forma explícita. Se reutiliza el
 * motor de equipos compuestos (estado y vencimiento efectivos, bloqueo si una
 * pieza no está apta) en lugar de sostener dos mecanismos para la misma idea.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->boolean('is_set')->default(false)->after('kind')
                ->comment('Equipo armado como conjunto de piezas: aparece en «Sets de equipos»');

            $table->index(['organization_id', 'is_set']);
        });

        // Lo que ya tenía componentes es, de hecho, un set: se marca para que
        // no desaparezca de la lista nueva. Sólo toca la tabla de esta base.
        $padres = DB::table('assets')->whereNotNull('parent_id')->distinct()->pluck('parent_id');

        if ($padres->isNotEmpty()) {
            DB::table('assets')->whereIn('id', $padres)->update(['is_set' => true]);
        }
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'is_set']);
            $table->dropColumn('is_set');
        });
    }
};
