<?php

namespace App\Http\Controllers;

use App\Actions\StoreDocument;
use App\Http\Requests\StoreDocumentRequest;
use App\Models\Asset;
use App\Models\Document;
use App\Models\InspectionReport;
use App\Support\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Documentos privados: carga, descarga y baja.
 *
 * Es el único camino hacia los archivos. El disco no es servible y está fuera
 * de public/, así que no existe una URL directa al PDF. Toda descarga se audita.
 */
class DocumentController extends Controller
{
    public function storeForReport(
        StoreDocumentRequest $request,
        InspectionReport $report,
        StoreDocument $store,
    ): RedirectResponse {
        $this->authorize('view', $report);

        $store(
            $report,
            $request->file('file'),
            $request->string('category')->value(),
            $request->input('document_date'),
            $request->input('notes'),
        );

        return back()->with('success', 'Documento adjuntado al informe.');
    }

    public function storeForAsset(
        StoreDocumentRequest $request,
        Asset $asset,
        StoreDocument $store,
    ): RedirectResponse {
        $this->authorize('view', $asset);

        $store(
            $asset,
            $request->file('file'),
            $request->string('category')->value(),
            $request->input('document_date'),
            $request->input('notes'),
        );

        return back()->with('success', 'Documento adjuntado a la pieza.');
    }

    public function download(Request $request, Document $document): StreamedResponse
    {
        $this->authorize('download', $document);

        $disk = Storage::disk(config('odessa.documents_disk'));

        abort_unless($disk->exists($document->path), 404, 'El documento ya no está disponible.');

        ActivityLogger::log(
            action: 'document.downloaded',
            subject: $document,
            description: "Descarga de «{$document->original_name}»",
            organizationId: $document->organization_id,
        );

        // Siempre como adjunto y con el tipo real: nunca se sirve inline
        // un archivo subido por un usuario.
        return $disk->download($document->path, $document->original_name, [
            'Content-Type' => $document->mime_type,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'attachment; filename="'.addslashes($document->original_name).'"',
        ]);
    }

    public function destroy(Document $document, StoreDocument $store): RedirectResponse
    {
        $this->authorize('delete', $document);

        $store->delete($document);

        return back()->with('success', 'Documento eliminado.');
    }
}
