<?php

namespace App\Actions;

use App\Models\Asset;
use App\Models\Organization;
use App\Support\ActivityLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Alta de una pieza en el inventario de una empresa.
 *
 * Único punto de creación. Hay tres caminos por los que entra un equipo
 * —alta manual, ingreso directo en el taller, y autorización de un envío que
 * declaraba una pieza no registrada— y antes cada uno creaba el registro por
 * su cuenta. La guarda de serie repetida vivía sólo en el primero, así que
 * autorizar un envío con una serie ya existente duplicaba la pieza y partía
 * su historial en dos.
 */
class CreateAsset
{
    /**
     * @param  array<string, mixed>  $data
     * @param  string|null  $reason  Qué originó el alta, para la bitácora.
     */
    public function __invoke(Organization $organization, array $data, ?string $reason = null): Asset
    {
        $this->guardAgainstDuplicateSerial($organization, $data['serial_number'] ?? null);

        $asset = DB::transaction(fn () => Asset::create([
            'organization_id' => $organization->getKey(),
            'kind' => $data['kind'] ?? Asset::KIND_PART,
            'description' => $data['description'],
            'workshop_description' => $data['workshop_description'] ?? null,
            'serial_number' => $data['serial_number'] ?? null,
            'client_reference' => $data['client_reference'] ?? null,
            'manufacturer_reference' => $data['manufacturer_reference'] ?? null,
            'brand' => $data['brand'] ?? null,
            'model' => $data['model'] ?? null,
            'identifier' => $data['identifier'] ?? null,
            'catalog_item_id' => $data['catalog_item_id'] ?? null,
            'manufacturer_id' => $data['manufacturer_id'] ?? null,
            'test_pressure_id' => $data['test_pressure_id'] ?? null,
            'working_pressure_id' => $data['working_pressure_id'] ?? null,
            'service_id' => $data['service_id'] ?? null,
            'connection_top_id' => $data['connection_top_id'] ?? null,
            'connection_bottom_id' => $data['connection_bottom_id'] ?? null,
            'connection_side_1_id' => $data['connection_side_1_id'] ?? null,
            'connection_side_2_id' => $data['connection_side_2_id'] ?? null,
            // Identificador interno. No se pide al usuario: se genera para que
            // toda pieza tenga uno aunque venga sin número de serie.
            'code' => $data['code'] ?? Str::upper(Str::random(8)),
        ]));

        ActivityLogger::log(
            action: 'asset.created',
            subject: $asset,
            description: $reason ?? "Alta de {$asset->displayName()}",
            new: ['serial_number' => $asset->serial_number, 'description' => $asset->description],
            organizationId: $organization->getKey(),
        );

        return $asset;
    }

    /**
     * Una serie identifica físicamente a la pieza dentro de su empresa.
     * Cargarla dos veces parte su historial: la mitad de las inspecciones
     * quedarían colgando de un registro y la otra mitad del otro.
     */
    private function guardAgainstDuplicateSerial(Organization $organization, ?string $serial): void
    {
        if (blank($serial)) {
            return;
        }

        $existente = Asset::query()
            ->forOrganization($organization->getKey())
            ->where('serial_number', $serial)
            ->first();

        if ($existente !== null) {
            throw ValidationException::withMessages([
                'serial_number' => sprintf(
                    'Ya existe una pieza de esta empresa con ese número de serie: «%s».',
                    $existente->displayName(),
                ),
            ]);
        }
    }
}
