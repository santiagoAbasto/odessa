<?php

namespace App\Actions;

use App\Models\Document;
use App\Support\ActivityLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Guarda un archivo adjunto en el disco privado.
 *
 * Medidas aplicadas, en orden:
 *  - el nombre en disco es aleatorio; el original sólo se guarda como dato,
 *    así un nombre malicioso nunca toca el sistema de archivos;
 *  - el MIME se toma del contenido real, no de lo que declara el navegador;
 *  - se calcula sha256 para detectar duplicados y verificar integridad;
 *  - la ruta se agrupa por organización, lo que facilita auditar y purgar.
 */
class StoreDocument
{
    /**
     * @param  Model  $documentable  InspectionReport o Asset
     */
    public function __invoke(
        Model $documentable,
        UploadedFile $file,
        string $category = 'other',
        ?string $documentDate = null,
        ?string $notes = null,
    ): Document {
        $disk = Storage::disk(config('odessa.documents_disk'));
        $organizationId = $documentable->organization_id;

        $extension = strtolower($file->getClientOriginalExtension());
        $storedName = Str::uuid7()->toString().($extension ? ".{$extension}" : '');
        $directory = 'org-'.$organizationId.'/'.now()->format('Y/m');

        $path = $file->storeAs($directory, $storedName, [
            'disk' => config('odessa.documents_disk'),
        ]);

        $document = new Document([
            'organization_id' => $organizationId,
            'category' => $category,
            // El nombre original se limpia: nunca se usa como ruta, pero sí se muestra.
            'original_name' => $this->sanitizeName($file->getClientOriginalName()),
            'path' => $path,
            'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
            'size_bytes' => $disk->size($path),
            'checksum' => hash_file('sha256', $file->getRealPath() ?: $disk->path($path)),
            'document_date' => $documentDate,
            'notes' => $notes,
            'uploaded_by' => Auth::id(),
        ]);

        $document->documentable()->associate($documentable);
        $document->save();

        ActivityLogger::log(
            action: 'document.uploaded',
            subject: $document,
            description: "Carga de «{$document->original_name}» en ".class_basename($documentable),
            organizationId: $organizationId,
        );

        return $document;
    }

    /** Quita rutas y caracteres de control del nombre visible. */
    private function sanitizeName(string $name): string
    {
        $base = basename(str_replace('\\', '/', $name));
        $clean = preg_replace('/[\x00-\x1F\x7F]/u', '', $base) ?? 'documento';

        return Str::limit(trim($clean), 180, '');
    }

    public function delete(Document $document): void
    {
        $disk = Storage::disk(config('odessa.documents_disk'));

        // Borrado lógico del registro; el binario se elimina de verdad.
        if ($disk->exists($document->path)) {
            $disk->delete($document->path);
        }

        ActivityLogger::log(
            action: 'document.deleted',
            subject: $document,
            description: "Eliminación de «{$document->original_name}»",
            organizationId: $document->organization_id,
        );

        $document->delete();
    }
}
