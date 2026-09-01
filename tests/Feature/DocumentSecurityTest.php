<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\Organization;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Documentos privados: carga, descarga y protección.
 *
 * El sistema auditado descargaba por un endpoint con id secuencial. Aquí la
 * ruta usa UUID, exige permiso, comprueba la organización y audita la descarga.
 */
class DocumentSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        Storage::fake('documents');
    }

    private function archivoDeInforme(?Organization $organization = null): Document
    {
        $organization ??= $this->organization();

        return $this->document($this->reportFor($this->asset($organization)));
    }

    // --- Descarga -------------------------------------------------------

    public function test_un_usuario_autorizado_descarga_el_documento(): void
    {
        $organizacion = $this->organization();
        $archivo = $this->archivoDeInforme($organizacion);

        $response = $this->actingAs($this->clientUser($organizacion))
            ->get("/documentos/{$archivo->public_id}/descargar");

        $response->assertOk();
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));
    }

    public function test_cada_descarga_queda_registrada_en_la_auditoria(): void
    {
        $organizacion = $this->organization();
        $archivo = $this->archivoDeInforme($organizacion);

        $this->actingAs($this->clientUser($organizacion))
            ->get("/documentos/{$archivo->public_id}/descargar");

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'document.downloaded',
            'subject_id' => $archivo->getKey(),
        ]);
    }

    public function test_un_visitante_sin_sesion_no_alcanza_el_documento(): void
    {
        $archivo = $this->archivoDeInforme();

        $this->get("/documentos/{$archivo->public_id}/descargar")->assertRedirect('/ingresar');
    }

    public function test_si_el_archivo_no_existe_en_disco_responde_404(): void
    {
        $organizacion = $this->organization();
        $archivo = $this->archivoDeInforme($organizacion);

        Storage::disk('documents')->delete($archivo->path);

        $this->actingAs($this->clientUser($organizacion))
            ->get("/documentos/{$archivo->public_id}/descargar")
            ->assertNotFound();
    }

    // --- Carga ----------------------------------------------------------

    public function test_el_taller_adjunta_un_documento_a_un_informe(): void
    {
        $organizacion = $this->organization();
        $informe = $this->reportFor($this->asset($organizacion));

        $this->actingAs($this->staffUser(Permissions::ROLE_WORKSHOP))
            ->post("/informes/{$informe->public_id}/documentos", [
                'file' => UploadedFile::fake()->create('certificado.pdf', 120, 'application/pdf'),
                'category' => 'fmr_report',
            ])
            ->assertRedirect();

        $documento = Document::withoutGlobalScope('organization')->first();

        $this->assertNotNull($documento);
        $this->assertSame($informe->getKey(), $documento->documentable_id);
        $this->assertSame($organizacion->getKey(), $documento->organization_id);
        Storage::disk('documents')->assertExists($documento->path);
    }

    public function test_el_taller_adjunta_un_documento_a_una_pieza(): void
    {
        $organizacion = $this->organization();
        $pieza = $this->asset($organizacion);

        $this->actingAs($this->staffUser(Permissions::ROLE_WORKSHOP))
            ->post("/activos/{$pieza->public_id}/documentos", [
                'file' => UploadedFile::fake()->create('ficha.pdf', 90, 'application/pdf'),
                'category' => 'datasheet',
            ])
            ->assertRedirect();

        $this->assertSame(1, $pieza->documents()->count());
    }

    /** El nombre en disco es aleatorio: un nombre malicioso nunca toca el sistema de archivos. */
    public function test_el_archivo_se_guarda_con_nombre_aleatorio(): void
    {
        $organizacion = $this->organization();
        $pieza = $this->asset($organizacion);

        $this->actingAs($this->staffUser(Permissions::ROLE_WORKSHOP))
            ->post("/activos/{$pieza->public_id}/documentos", [
                'file' => UploadedFile::fake()->create('../../etc/passwd.pdf', 10, 'application/pdf'),
                'category' => 'other',
            ]);

        $documento = Document::withoutGlobalScope('organization')->first();

        $this->assertStringNotContainsString('passwd', $documento->path);
        $this->assertStringNotContainsString('..', $documento->path);
        $this->assertStringNotContainsString('/', $documento->original_name);
    }

    public function test_se_rechaza_un_tipo_de_archivo_no_permitido(): void
    {
        $organizacion = $this->organization();
        $pieza = $this->asset($organizacion);

        $this->actingAs($this->staffUser(Permissions::ROLE_WORKSHOP))
            ->post("/activos/{$pieza->public_id}/documentos", [
                'file' => UploadedFile::fake()->create('script.php', 10, 'application/x-php'),
                'category' => 'other',
            ])
            ->assertSessionHasErrors('file');

        $this->assertSame(0, Document::withoutGlobalScope('organization')->count());
    }

    public function test_un_cliente_no_puede_subir_documentos(): void
    {
        $organizacion = $this->organization();
        $pieza = $this->asset($organizacion);

        $this->actingAs($this->clientUser($organizacion, Permissions::ROLE_CLIENT_OPERATOR))
            ->post("/activos/{$pieza->public_id}/documentos", [
                'file' => UploadedFile::fake()->create('intento.pdf', 10, 'application/pdf'),
                'category' => 'other',
            ])
            ->assertForbidden();

        $this->assertSame(0, Document::withoutGlobalScope('organization')->count());
    }

    public function test_borrar_un_documento_elimina_el_binario_del_disco(): void
    {
        $organizacion = $this->organization();
        $archivo = $this->archivoDeInforme($organizacion);
        $ruta = $archivo->path;

        $this->actingAs($this->staffUser(Permissions::ROLE_WORKSHOP))
            ->delete("/documentos/{$archivo->public_id}")
            ->assertRedirect();

        Storage::disk('documents')->assertMissing($ruta);
        $this->assertSoftDeleted('documents', ['id' => $archivo->getKey()]);
    }

    // --- Diseño ---------------------------------------------------------

    /** El disco privado nunca debe quedar bajo public/. */
    public function test_el_disco_de_documentos_esta_fuera_del_directorio_publico(): void
    {
        $raiz = config('filesystems.disks.documents.root');

        $this->assertStringNotContainsString(public_path(), $raiz);
        $this->assertFalse(config('filesystems.disks.documents.serve'));
    }

    public function test_las_rutas_de_documentos_usan_uuid_y_no_ids_secuenciales(): void
    {
        $archivo = $this->archivoDeInforme();

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $archivo->public_id,
        );
        $this->assertSame('public_id', $archivo->getRouteKeyName());
    }
}
