<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ProductionCheck extends Command
{
    protected $signature = 'app:production-check {--skip-database : Omite conexión y migraciones}';

    protected $description = 'Verifica que el entorno esté listo para recibir tráfico de producción';

    /** @var list<string> */
    private array $failures = [];

    public function handle(): int
    {
        $this->components->info('Comprobación de producción');

        $this->check('APP_ENV es production', app()->isProduction());
        $this->check('APP_DEBUG está desactivado', config('app.debug') === false);
        $this->check('APP_URL usa HTTPS', str_starts_with((string) config('app.url'), 'https://'));
        $this->check('APP_KEY está configurada', filled(config('app.key')));
        $this->check('Cookie de sesión Secure', config('session.secure') === true);
        $this->check('Cookie de sesión HttpOnly', config('session.http_only') === true);
        $this->check('Sesión cifrada', config('session.encrypt') === true);
        $this->check('SameSite es lax o strict', in_array(config('session.same_site'), ['lax', 'strict'], true));
        $this->check('Sesiones persistentes', ! in_array(config('session.driver'), ['array', 'cookie'], true));
        $this->check('Mailer real configurado', ! in_array(config('mail.default'), ['array', 'log'], true));
        $this->check('Cola persistente configurada', ! in_array(config('queue.default'), ['sync', 'null'], true));
        $this->check('Manifiesto Vite presente', is_file(public_path('build/manifest.json')));
        $this->check('storage es escribible', is_writable(storage_path()));
        $this->check('bootstrap/cache es escribible', is_writable(base_path('bootstrap/cache')));
        $this->check('Documentos fuera de public', $this->documentsArePrivate());
        $this->check('Extensión PHP fileinfo disponible', extension_loaded('fileinfo'));
        $this->check('Extensión PHP PDO MySQL disponible', extension_loaded('pdo_mysql'));

        $this->checkDocumentsDisk();

        if (! $this->option('skip-database')) {
            $this->checkDatabase();
        }

        if ($this->failures !== []) {
            $this->newLine();
            $this->components->error(count($this->failures).' comprobaciones fallaron. No habilite tráfico todavía.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->components->info('Entorno listo para producción.');

        return self::SUCCESS;
    }

    private function check(string $label, bool $passes, ?string $detail = null): void
    {
        if ($passes) {
            $this->components->twoColumnDetail($label, '<fg=green>OK</>');

            return;
        }

        $this->failures[] = $label;
        $this->components->twoColumnDetail($label, '<fg=red>FALLÓ</>');

        if ($detail) {
            $this->line("  <fg=yellow>{$detail}</>");
        }
    }

    private function documentsArePrivate(): bool
    {
        $root = (string) config('filesystems.disks.'.config('odessa.documents_disk').'.root');
        $public = realpath(public_path()) ?: public_path();
        $documents = realpath($root) ?: $root;

        return $root !== '' && ! str_starts_with($documents, $public.DIRECTORY_SEPARATOR);
    }

    private function checkDocumentsDisk(): void
    {
        $path = '.production-check-'.bin2hex(random_bytes(8));

        try {
            $disk = Storage::disk(config('odessa.documents_disk'));
            $written = $disk->put($path, 'ok');
            $readBack = $written && $disk->get($path) === 'ok';
            $deleted = $disk->delete($path);
            $this->check('Disco privado permite escritura, lectura y borrado', $readBack && $deleted);
        } catch (Throwable $exception) {
            $this->check('Disco privado permite escritura, lectura y borrado', false, $exception->getMessage());
        }
    }

    private function checkDatabase(): void
    {
        try {
            DB::select('select 1');
            $this->check('Conexión a la base de datos', true);

            $ran = DB::table('migrations')->pluck('migration')->all();
            $files = glob(database_path('migrations/*_*.php')) ?: [];
            $known = array_map(fn (string $file) => pathinfo($file, PATHINFO_FILENAME), $files);
            $this->check('No hay migraciones pendientes', array_diff($known, $ran) === []);
        } catch (Throwable $exception) {
            $this->check('Conexión a la base de datos', false, $exception->getMessage());
        }
    }
}
