<?php

namespace App\Http\Controllers;

use App\Actions\ManageExternalCertification;
use App\Models\Asset;
use App\Models\AssetExternalCertification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Certificaciones que el equipo ya traía de otra empresa, cargadas a mano en su
 * ficha. Las reglas viven en ManageExternalCertification.
 */
class AssetExternalCertificationController extends Controller
{
    /** Carga una nueva o edita la de esa operación. */
    public function store(Request $request, Asset $asset, ManageExternalCertification $manage): RedirectResponse
    {
        $this->authorize('certifyExternally', $asset);

        $data = $request->validate([
            'service_id' => ['required', 'integer'],
            'certified_by' => ['required', 'string', 'max:160'],
            'certificate_number' => ['nullable', 'string', 'max:120'],
            'certified_at' => ['required', 'date', 'before_or_equal:today'],
            'expires_at' => ['required', 'date', 'after:certified_at'],
        ], [
            'service_id.required' => 'Elija qué operación certificó.',
            'certified_by.required' => 'Indique qué empresa la certificó.',
            'certified_at.required' => 'Indique cuándo se certificó.',
            'certified_at.before_or_equal' => 'La fecha de certificación no puede ser futura.',
            'expires_at.required' => 'Indique hasta cuándo vale.',
            'expires_at.after' => 'El vencimiento tiene que ser posterior a la fecha de certificación.',
        ]);

        $manage->save($asset, $data);

        return back()->with('success', 'Certificación cargada en la ficha. Quedó registrada en la auditoría como carga manual, no como informe.');
    }

    public function destroy(Asset $asset, AssetExternalCertification $certification, ManageExternalCertification $manage): RedirectResponse
    {
        $this->authorize('certifyExternally', $asset);

        // Evita borrar la de otro equipo cambiando el id en la URL.
        abort_unless($certification->asset_id === $asset->getKey(), 404);

        $manage->remove($asset, $certification);

        return back()->with('success', 'Certificación quitada de la ficha.');
    }
}
