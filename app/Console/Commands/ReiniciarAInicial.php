<?php

namespace App\Console\Commands;

use App\Actions\ImportarInventario;
use App\Models\ActivityLog;
use App\Models\Organization;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Deja la base de producción como el día de la puesta en marcha: sin pruebas,
 * con el inventario del cliente recargado desde el archivo original y sólo con
 * los usuarios que se indiquen (el superadministrador y el que corresponda).
 *
 * Es destructivo, así que está armado para no poder tocar lo que no debe:
 *
 *  - Opera sobre UNA base: `--base` tiene que coincidir con la base conectada,
 *    o no hace nada. Nunca recorre el listado de tablas del servidor (eso, en
 *    MySQL, trae las de TODAS las bases: el incidente del 29/10/2026); vacía una
 *    lista fija y explícita de tablas, la de abajo.
 *  - Sin `--confirmar` es una corrida de prueba: cuenta y lista, no borra.
 *  - Todo (vaciar, borrar usuarios, recargar) va en una sola transacción: si algo
 *    falla, la base queda como estaba. Antes de borrar se lee el inventario
 *    completo, así un archivo roto no llega a tocar nada.
 *  - Se conservan los catálogos de configuración (operaciones, sus días de taller
 *    y su habilitación por empresa), los roles y permisos y la organización del
 *    cliente. Los modelos de producto, fabricantes, presiones y conexiones se
 *    vacían y se rearman desde el inventario, como el primer día.
 *
 * Antes de correrlo de verdad: backup de esa base (mysqldump a un .sql,
 * `gzip -t`, contar los CREATE TABLE) y la corrida de prueba.
 */
class ReiniciarAInicial extends Command
{
    protected $signature = 'odessa:reiniciar-a-inicial
                            {archivo : Inventario original del cliente (.json, o .xlsx donde haya ext-zip)}
                            {--base= : Nombre de la base; tiene que coincidir con la conectada}
                            {--conservar= : Correos de los usuarios que quedan, separados por coma (uno tiene que ser superadministrador)}
                            {--org=HASA : Código corto de la organización del cliente}
                            {--confirmar : Sin esto no se borra nada: sólo cuenta y lista}';

    protected $description = 'Vacía las pruebas de producción y recarga el inventario original del cliente';

    /**
     * Datos operativos que se vacían, con las tablas hijas antes que las madres.
     * Lista explícita: no se arma recorriendo las tablas de la base.
     */
    private const VACIAR = [
        'shipment_item_operations', 'shipment_items', 'receptions', 'shipments',
        'inspection_report_asset', 'inspection_reports', 'asset_external_certifications',
        'asset_set_asset', 'asset_sets', 'documents', 'comments', 'assets',
        'catalog_items', 'manufacturers', 'pressure_ratings', 'connections',
        'recertification_alerts', 'notifications', 'activity_logs', 'account_requests',
        'password_invitation_tokens', 'password_reset_tokens', 'sessions',
        'cache', 'cache_locks', 'jobs', 'failed_jobs', 'job_batches',
    ];

    public function handle(ImportarInventario $importar): int
    {
        $base = DB::connection()->getDatabaseName();

        if ((string) $this->option('base') !== (string) $base) {
            $this->error(sprintf(
                'La base conectada es «%s» y --base dice «%s». No se hizo nada: escribí --base=%s si es esa.',
                $base, (string) $this->option('base'), $base,
            ));

            return self::FAILURE;
        }

        $archivo = (string) $this->argument('archivo');

        if (! is_file($archivo)) {
            $this->error("No existe el archivo: {$archivo}");

            return self::FAILURE;
        }

        // El inventario se lee entero ANTES de tocar nada: un archivo roto corta acá.
        try {
            $equipos = app(CargarProduccion::class)->leer($archivo);
        } catch (\Throwable $e) {
            $this->error('No se pudo leer el inventario: '.$e->getMessage());

            return self::FAILURE;
        }

        $correos = collect(explode(',', (string) $this->option('conservar')))
            ->map(fn (string $c) => mb_strtolower(trim($c)))
            ->filter()
            ->unique()
            ->values();

        if ($correos->isEmpty()) {
            $this->error('Falta --conservar con los correos de los usuarios que quedan (el superadministrador y quien corresponda).');

            return self::FAILURE;
        }

        $quedan = User::withoutGlobalScopes()->whereIn(DB::raw('lower(email)'), $correos->all())->get();
        $faltan = $correos->diff($quedan->pluck('email')->map(fn ($e) => mb_strtolower($e)));

        if ($faltan->isNotEmpty()) {
            $this->error('No existen estos usuarios, no se hizo nada: '.$faltan->implode(', '));

            return self::FAILURE;
        }

        if (! $quedan->contains(fn (User $u) => $u->hasRole(Permissions::ROLE_SUPER_ADMIN))) {
            $this->error('Entre los que quedan tiene que haber un superadministrador: la base no puede quedar sin administrador.');

            return self::FAILURE;
        }

        $codigo = (string) $this->option('org');
        $orgCliente = Organization::query()->where('short_code', $codigo)->first();

        // Un usuario que se conserva no puede colgar de una organización que se va a borrar.
        $ajenos = $quedan->filter(fn (User $u) => $u->organization_id !== null && (int) $u->organization_id !== (int) $orgCliente?->getKey());
        if ($ajenos->isNotEmpty()) {
            $this->error('Estos usuarios pertenecen a una organización que se borraría, revisalo a mano: '.$ajenos->pluck('email')->implode(', '));

            return self::FAILURE;
        }

        $this->informe($base, $quedan, $orgCliente, count($equipos), $codigo);

        if (! $this->option('confirmar')) {
            $this->info('Corrida de prueba: no se borró nada. Con --confirmar se aplica.');

            return self::SUCCESS;
        }

        // Los archivos de los documentos se borran DESPUÉS de confirmar la transacción.
        $archivos = Schema::hasTable('documents')
            ? DB::table('documents')->pluck('path')
            : collect();

        $conteo = DB::transaction(function () use ($importar, $equipos, $quedan, $codigo, $correos) {
            $this->vaciar();
            $this->quitarUsuariosYOrganizaciones($quedan->pluck('id')->all(), $codigo);

            $org = Organization::firstOrCreate(
                ['short_code' => $codigo],
                ['name' => $codigo, 'is_active' => true],
            );

            $conteo = $importar($org, $equipos, CargarProduccion::EXCLUIR_SERIES);

            ActivityLog::create([
                'organization_id' => null,
                'user_id' => null,
                'action' => 'system.reset_to_initial',
                'description' => 'Se reinició la base: se vaciaron las pruebas y se recargó el inventario original. Quedaron los usuarios: '.$correos->implode(', '),
            ]);

            return $conteo;
        });

        $disco = Storage::disk(config('odessa.documents_disk'));
        $sueltos = 0;
        foreach ($archivos as $ruta) {
            try {
                if ($ruta && $disco->delete($ruta)) {
                    $sueltos++;
                }
            } catch (\Throwable) {
                // Un archivo que no se pudo borrar no deshace el reinicio: queda huérfano.
            }
        }

        $this->info(sprintf(
            'Listo. Cargados %d modelos y %d equipos (%d sin certificar). Archivos de documentos borrados: %d.',
            $conteo['modelos'], $conteo['equipos'], $conteo['sin_certificar'], $sueltos,
        ));
        $this->line('Todas las sesiones se cerraron: hay que volver a ingresar. Ahora conviene sacar el dump de la base limpia.');

        return self::SUCCESS;
    }

    /** Qué va a pasar, con COUNT(*) real de cada tabla. */
    private function informe(string $base, $quedan, ?Organization $orgCliente, int $filas, string $codigo): void
    {
        $this->line("Base: {$base}");
        $this->line("Inventario a cargar: {$filas} filas · organización {$codigo}".($orgCliente ? '' : ' (se crea)'));
        $this->newLine();

        $this->table(['Tabla (se vacía)', 'Filas hoy'], collect(self::VACIAR)
            ->filter(fn (string $t) => Schema::hasTable($t))
            ->map(fn (string $t) => [$t, DB::table($t)->count()])
            ->all());

        $mantiene = $quedan->pluck('id')->map(fn ($id) => (int) $id)->all();
        $usuarios = User::withoutGlobalScopes()->with('roles:name')->orderBy('id')->get();

        $this->table(['Usuario', 'Nombre', 'Rol', 'Qué pasa'], $usuarios->map(fn (User $u) => [
            $u->email, $u->name, $u->roles->pluck('name')->implode(', '),
            in_array((int) $u->getKey(), $mantiene, true) ? 'SE CONSERVA' : 'se borra',
        ])->all());

        $orgs = Organization::query()->get(['id', 'name', 'short_code']);
        $this->table(['Organización', 'Qué pasa'], $orgs->map(fn (Organization $o) => [
            "{$o->name} ({$o->short_code})",
            $o->short_code === $codigo ? 'SE CONSERVA' : 'se borra con sus datos',
        ])->all());

        $this->line('Se conservan: operaciones y sus días de taller, habilitación por empresa, roles y permisos.');
        $this->newLine();
    }

    private function vaciar(): void
    {
        // Referencias circulares dentro de assets y hacia los informes: se cortan antes de borrar.
        if (Schema::hasTable('assets')) {
            DB::table('assets')->update(['parent_id' => null, 'current_inspection_report_id' => null]);
        }

        foreach (self::VACIAR as $tabla) {
            if (Schema::hasTable($tabla)) {
                DB::table($tabla)->delete();
            }
        }
    }

    /** @param  list<int>  $idsQueQuedan */
    private function quitarUsuariosYOrganizaciones(array $idsQueQuedan, string $codigo): void
    {
        $sobran = DB::table('users')->whereNotIn('id', $idsQueQuedan)->pluck('id')->all();

        if ($sobran !== []) {
            foreach (['model_has_roles', 'model_has_permissions'] as $tabla) {
                DB::table($tabla)->where('model_type', User::class)->whereIn('model_id', $sobran)->delete();
            }

            DB::table('users')->whereIn('id', $sobran)->delete();
        }

        $otras = DB::table('organizations')->where('short_code', '!=', $codigo)->pluck('id')->all();

        if ($otras !== []) {
            DB::table('organization_service')->whereIn('organization_id', $otras)->delete();
            DB::table('organizations')->whereIn('id', $otras)->delete();
        }
    }
}
