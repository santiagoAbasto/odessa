<?php

namespace Tests\Feature;

use App\Actions\ImportarInventario;
use App\Models\ActivityLog;
use App\Models\Asset;
use App\Models\Document;
use App\Models\InspectionReport;
use App\Models\Organization;
use App\Models\Reception;
use App\Models\Service;
use App\Models\Shipment;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * `odessa:reiniciar-a-inicial`: deja producción como el primer día (05/11/2026).
 *
 * Es destructivo, así que casi todo lo que se prueba es que NO borra cuando no
 * debe: otra base, un archivo roto, sin superadministrador, usuarios que no
 * existen, o una falla a mitad de camino. Se corre sobre SQLite en memoria: nunca
 * sobre una base MySQL.
 */
class ReiniciarAInicialTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $agustin;

    private Organization $hasa;

    private Organization $prueba;

    private string $json;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        Storage::fake(config('odessa.documents_disk'));

        $this->hasa = Organization::create(['name' => 'HASA', 'short_code' => 'HASA', 'is_active' => true]);
        $this->prueba = $this->organization('PRUEBA');

        $this->admin = $this->staffUser(Permissions::ROLE_SUPER_ADMIN);
        $this->agustin = $this->staffUser(Permissions::ROLE_WORKSHOP);

        // Todo lo que tiene que desaparecer: lo de las pruebas.
        $cliente = $this->clientUser($this->prueba);
        $pieza = $this->asset($this->prueba);
        $this->asset($this->hasa, ['description' => 'Equipo de prueba en HASA']);
        $informe = $this->reportFor($pieza);
        Reception::create(['organization_id' => $this->prueba->getKey(), 'asset_id' => $pieza->getKey(), 'received_at' => today()]);
        Shipment::create(['organization_id' => $this->prueba->getKey(), 'reference' => 'ENV-2026-0001']);
        $this->document($pieza);
        ActivityLog::create(['action' => 'x.prueba', 'description' => 'registro de prueba', 'user_id' => $cliente->getKey()]);

        // Configuración que tiene que sobrevivir: operaciones, sus días y su habilitación.
        $cos = Service::create(['code' => 'COS', 'name' => 'Certificación', 'determines_fitness' => true, 'validity_days' => 365, 'turnaround_days' => 20]);
        $this->hasa->operations()->attach($cos->getKey());
        $this->prueba->operations()->attach($cos->getKey());

        $this->json = tempnam(sys_get_temp_dir(), 'hasa').'.json';
        file_put_contents($this->json, json_encode([
            ['HASA', 'REF-1', 'SERIE-A', 'L-100', 'ELMAR', 'desc', 'SB / GIE', '', 'ELMAR', '0', '0', '0', '0', '10M', '15M', '2026-09-05', '', ''],
            ['HASA', 'REF-2', 'SERIE-B', 'L-100', 'ELMAR', 'desc', 'SB / GIE', '', 'ELMAR', '0', '0', '0', '0', '10M', '15M', '', '', ''],
        ]));

        $this->assertNotNull($informe);
    }

    protected function tearDown(): void
    {
        @unlink($this->json);
        parent::tearDown();
    }

    private function correr(array $opciones = [], ?string $archivo = null): int
    {
        return Artisan::call('odessa:reiniciar-a-inicial', [
            'archivo' => $archivo ?? $this->json,
            '--base' => DB::connection()->getDatabaseName(),
            '--conservar' => $this->admin->email.','.$this->agustin->email,
            ...$opciones,
        ]);
    }

    /** Lo operativo de hoy, para comprobar que no cambió. */
    private function foto(): array
    {
        return [
            'users' => User::withoutGlobalScopes()->count(),
            'orgs' => Organization::query()->count(),
            'assets' => Asset::withoutGlobalScopes()->count(),
            'reports' => InspectionReport::withoutGlobalScopes()->count(),
            'receptions' => Reception::withoutGlobalScopes()->count(),
            'shipments' => Shipment::withoutGlobalScopes()->count(),
            'documents' => Document::withoutGlobalScopes()->count(),
            'logs' => ActivityLog::query()->count(),
        ];
    }

    public function test_la_corrida_de_prueba_cuenta_y_lista_pero_no_borra(): void
    {
        $antes = $this->foto();

        $this->assertSame(0, $this->correr());

        $salida = Artisan::output();
        $this->assertStringContainsString('Corrida de prueba: no se borró nada', $salida);
        $this->assertStringContainsString('SE CONSERVA', $salida);
        $this->assertStringContainsString('se borra', $salida);
        $this->assertSame($antes, $this->foto());
    }

    public function test_con_confirmar_deja_la_base_como_el_primer_dia(): void
    {
        $archivos = Document::withoutGlobalScopes()->pluck('path')->all();
        $this->assertNotEmpty($archivos);

        $this->assertSame(0, $this->correr(['--confirmar' => true]));

        // Sólo quedan los dos usuarios, con sus roles.
        $this->assertEqualsCanonicalizing(
            [$this->admin->email, $this->agustin->email],
            User::withoutGlobalScopes()->pluck('email')->all(),
        );
        $this->assertTrue($this->admin->fresh()->hasRole(Permissions::ROLE_SUPER_ADMIN));
        $this->assertTrue($this->agustin->fresh()->hasRole(Permissions::ROLE_WORKSHOP));

        // Sólo la organización del cliente, con el inventario recargado.
        $this->assertSame(['HASA'], Organization::query()->pluck('short_code')->all());
        $this->assertSame(2, Asset::withoutGlobalScopes()->count());
        $this->assertSame(['SERIE-A', 'SERIE-B'], Asset::withoutGlobalScopes()->orderBy('serial_number')->pluck('serial_number')->all());
        // Un informe (COS) por el equipo que traía certificación; nada más.
        $this->assertSame(1, InspectionReport::withoutGlobalScopes()->count());

        // Ni rastro de las pruebas.
        $this->assertSame(0, Reception::withoutGlobalScopes()->count());
        $this->assertSame(0, Shipment::withoutGlobalScopes()->count());
        $this->assertSame(0, Document::withoutGlobalScopes()->count());
        foreach ($archivos as $ruta) {
            Storage::disk(config('odessa.documents_disk'))->assertMissing($ruta);
        }

        // La configuración sobrevive: operación, sus días y la habilitación de HASA (la de la otra empresa se va con ella).
        $cos = Service::query()->where('code', 'COS')->firstOrFail();
        $this->assertSame(20, (int) $cos->turnaround_days);
        $this->assertSame(1, DB::table('organization_service')->count());
        $this->assertSame($this->hasa->getKey(), (int) DB::table('organization_service')->value('organization_id'));
        $this->assertGreaterThan(0, DB::table('roles')->count());

        // Queda constancia de quién y qué se reinició.
        $this->assertTrue(ActivityLog::query()->where('action', 'system.reset_to_initial')->exists());
    }

    public function test_el_equipo_recargado_toma_los_dias_de_taller_de_la_operacion_que_se_conservo(): void
    {
        $this->correr(['--confirmar' => true]);

        $conCos = Asset::withoutGlobalScopes()->where('serial_number', 'SERIE-A')->firstOrFail();

        // La certificación cargada vence a los 365 días y «mandar antes de» resta los 20 días de COS y el margen de 15.
        $this->assertSame('2027-09-05', $conCos->expires_at->toDateString());
        $this->assertSame('2027-08-01', $conCos->send_by_at->toDateString());
    }

    public function test_no_hace_nada_si_la_base_no_coincide(): void
    {
        $antes = $this->foto();

        $this->assertSame(1, $this->correr(['--base' => 'otra_base', '--confirmar' => true]));

        $this->assertStringContainsString('No se hizo nada', Artisan::output());
        $this->assertSame($antes, $this->foto());
    }

    public function test_exige_que_entre_los_que_quedan_haya_un_superadministrador(): void
    {
        $antes = $this->foto();

        $this->assertSame(1, $this->correr(['--conservar' => $this->agustin->email, '--confirmar' => true]));

        $this->assertStringContainsString('superadministrador', Artisan::output());
        $this->assertSame($antes, $this->foto());
    }

    public function test_exige_que_los_usuarios_a_conservar_existan(): void
    {
        $antes = $this->foto();

        $this->assertSame(1, $this->correr(['--conservar' => $this->admin->email.',nadie@no-existe.test', '--confirmar' => true]));

        $this->assertStringContainsString('nadie@no-existe.test', Artisan::output());
        $this->assertSame($antes, $this->foto());
    }

    public function test_sin_conservar_no_corre(): void
    {
        $antes = $this->foto();

        $this->assertSame(1, $this->correr(['--conservar' => '', '--confirmar' => true]));

        $this->assertSame($antes, $this->foto());
    }

    public function test_no_conserva_un_usuario_de_una_organizacion_que_se_borra(): void
    {
        $antes = $this->foto();
        $cliente = User::withoutGlobalScopes()->where('organization_id', $this->prueba->getKey())->firstOrFail();

        $this->assertSame(1, $this->correr(['--conservar' => $this->admin->email.','.$cliente->email, '--confirmar' => true]));

        $this->assertStringContainsString($cliente->email, Artisan::output());
        $this->assertSame($antes, $this->foto());
    }

    public function test_un_inventario_roto_no_toca_nada(): void
    {
        $antes = $this->foto();
        $roto = tempnam(sys_get_temp_dir(), 'roto').'.json';
        file_put_contents($roto, '{esto no es una lista');

        $this->assertSame(1, $this->correr(['--confirmar' => true], $roto));

        $this->assertSame($antes, $this->foto());
        @unlink($roto);
    }

    public function test_si_falla_a_mitad_de_camino_todo_queda_como_estaba(): void
    {
        $antes = $this->foto();
        $this->mock(ImportarInventario::class, fn ($m) => $m->shouldReceive('__invoke')->andThrow(new \RuntimeException('falló la carga')));

        try {
            $this->correr(['--confirmar' => true]);
            $this->fail('Tenía que propagar la falla de la carga.');
        } catch (\RuntimeException $e) {
            $this->assertSame('falló la carga', $e->getMessage());
        }

        $this->assertSame($antes, $this->foto());
    }

    public function test_nunca_recorre_el_listado_de_tablas_del_servidor(): void
    {
        $fuente = file_get_contents(app_path('Console/Commands/ReiniciarAInicial.php'));

        // El 29/10/2026 eso vació las 58 bases MySQL de la Mac: en MySQL trae las tablas de todas las bases.
        $this->assertStringNotContainsString('getTableListing', $fuente);
        $this->assertStringNotContainsString('getTables(', $fuente);
        $this->assertStringNotContainsString('truncate', strtolower($fuente));
        $this->assertStringNotContainsString('FOREIGN_KEY_CHECKS', $fuente);
    }
}
