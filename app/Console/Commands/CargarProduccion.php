<?php

namespace App\Console\Commands;

use App\Actions\ImportarInventario;
use App\Models\Organization;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use OpenSpout\Reader\XLSX\Reader;

/**
 * Carga la base real del cliente y deja el sistema en limpio.
 *
 * Borra todo lo demo —conserva sólo al superadministrador dd@odessaoil.com y
 * los roles— y carga los modelos y equipos desde el Excel. Pensado para correr
 * UNA vez, en el momento de la puesta en producción, con un backup hecho antes.
 *
 * `--dry-run` sólo lee el Excel y muestra el conteo, sin tocar nada: sirve para
 * verificar que el archivo se lee bien antes de la corrida real.
 */
class CargarProduccion extends Command
{
    protected $signature = 'odessa:cargar-produccion
                            {archivo : Ruta del .xlsx en el servidor}
                            {--org=HASA : Código corto de la organización del cliente}
                            {--dry-run : Sólo lee y cuenta, no borra ni carga}
                            {--confirmar : Requerido para borrar lo demo y cargar}';

    /** Correos que se eliminaron durante la limpieza demo. Todo lo demás, afuera. */
    private const SUPERADMIN = 'dd@odessaoil.com';

    /** Series a dejar afuera (duplicadas / a pedido del cliente). */
    private const EXCLUIR_SERIES = ['13838266'];

    /** Tablas que la limpieza NO toca: migraciones, roles y el usuario que se conserva. */
    private const CONSERVAR = [
        'migrations', 'permissions', 'roles', 'role_has_permissions',
        'model_has_roles', 'model_has_permissions', 'users',
    ];

    public function handle(ImportarInventario $importar): int
    {
        $archivo = (string) $this->argument('archivo');

        if (! is_file($archivo)) {
            $this->error("No existe el archivo: {$archivo}");

            return self::FAILURE;
        }

        $equipos = $this->leer($archivo);

        $conCos = collect($equipos)->filter(fn ($f) => ! $this->vacia($f[15] ?? ''))->count();
        $this->line("Leídos: {$this->count($equipos)} equipos ({$conCos} con COS). El maestro de productos se arma a partir de ellos.");

        if ($this->option('dry-run')) {
            $this->info('Dry-run: no se tocó nada.');

            return self::SUCCESS;
        }

        if (! $this->option('confirmar')) {
            $this->error('Falta --confirmar. Esto BORRA todo lo demo (deja sólo al superadmin dd@odessaoil.com) y carga la base real. Hacé un backup antes.');

            return self::FAILURE;
        }

        $dd = User::where('email', self::SUPERADMIN)
            ->whereHas('roles', fn ($q) => $q->where('name', Permissions::ROLE_SUPER_ADMIN))
            ->first();

        if ($dd === null) {
            $this->error('No existe el superadmin '.self::SUPERADMIN.'. Creálo antes: la limpieza no debe dejar el sistema sin administrador.');

            return self::FAILURE;
        }

        $this->limpiar($dd->getKey());

        $org = Organization::firstOrCreate(
            ['short_code' => (string) $this->option('org')],
            ['name' => (string) $this->option('org'), 'is_active' => true]
        );

        $conteo = DB::transaction(fn () => $importar($org, $equipos, self::EXCLUIR_SERIES));

        $this->info(sprintf(
            'Cargado: %d modelos, %d equipos (%d sin certificar). %d filas de equipo quedaron afuera (serie vacía o duplicada).',
            $conteo['modelos'], $conteo['equipos'], $conteo['sin_certificar'], $conteo['excluidos'],
        ));

        return self::SUCCESS;
    }

    /**
     * Borra todo lo demo: vacía las tablas de datos y deja sólo al superadmin y
     * los roles. No corre migraciones, así el esquema queda intacto.
     */
    private function limpiar(int $ddId): void
    {
        // getTableListing() devuelve nombres calificados «esquema.tabla» y puede
        // incluir OTRAS bases del mismo servidor: hay que vaciar sólo las tablas
        // de esta base, nunca las de otra.
        $base = DB::connection()->getDatabaseName();

        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        foreach (Schema::getTableListing() as $calificado) {
            [$esquema, $tabla] = str_contains($calificado, '.')
                ? explode('.', $calificado, 2)
                : [$base, $calificado];

            if ($esquema !== $base || in_array($tabla, self::CONSERVAR, true)) {
                continue;
            }

            DB::table($tabla)->truncate();
        }

        DB::table('users')->where('id', '!=', $ddId)->delete();
        DB::table('model_has_roles')->where('model_id', '!=', $ddId)->delete();
        DB::table('model_has_permissions')->where('model_id', '!=', $ddId)->delete();

        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        $this->line('Limpieza demo: sólo queda el superadmin dd@odessaoil.com.');
    }

    /**
     * Lee la hoja "Tabla" (equipos) a filas, sin encabezado. El maestro de
     * productos se deriva de estos equipos, así que no se lee la hoja padre
     * (que en el Excel son fórmulas).
     *
     * En el servidor la consola corre PHP SIN ext-zip, así que no puede abrir
     * un .xlsx (es un zip). Para la corrida real se sube un .json con estas
     * mismas filas ya extraídas; el .xlsx sirve para correr en local, donde sí
     * hay zip. El .json no necesita openspout ni ext-zip.
     *
     * @return list<array<int, mixed>>
     */
    private function leer(string $archivo): array
    {
        if (str_ends_with(strtolower($archivo), '.json')) {
            return $this->leerJson($archivo);
        }

        $filas = [];

        $reader = new Reader();
        $reader->open($archivo);

        foreach ($reader->getSheetIterator() as $sheet) {
            if ($sheet->getName() !== 'Tabla') {
                continue;
            }

            $primera = true;
            foreach ($sheet->getRowIterator() as $row) {
                if ($primera) {
                    $primera = false;

                    continue;
                }

                $celdas = $row->toArray();
                if (array_filter($celdas, fn ($c) => ! $this->vacia($c)) !== []) {
                    $filas[] = $celdas;
                }
            }
        }

        $reader->close();

        return $filas;
    }

    /**
     * Filas de equipo ya extraídas a JSON: una lista de filas, cada fila la
     * lista de celdas en el orden de la hoja "Tabla" (sin encabezado). Las
     * fechas vienen como texto "Y-m-d". No necesita ext-zip.
     *
     * @return list<array<int, mixed>>
     */
    private function leerJson(string $archivo): array
    {
        $datos = json_decode((string) file_get_contents($archivo), true);

        if (! is_array($datos)) {
            throw new \RuntimeException("El JSON no es una lista de filas: {$archivo}");
        }

        return array_values(array_filter(
            $datos,
            fn ($fila) => is_array($fila) && array_filter($fila, fn ($c) => ! $this->vacia($c)) !== [],
        ));
    }

    /** @param  list<mixed>  $filas */
    private function count(array $filas): int
    {
        return count($filas);
    }

    /** ¿La celda está vacía? Una fecha (DateTime) nunca lo está. */
    private function vacia(mixed $c): bool
    {
        if ($c instanceof \DateTimeInterface) {
            return false;
        }

        return trim((string) $c) === '';
    }
}
