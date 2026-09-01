<?php

namespace App\Actions;

use App\Models\Asset;
use App\Models\Organization;
use App\Models\Reception;
use App\Support\ActivityLogger;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Ingreso directo: el taller da de alta un equipo que llegó sin envío previo.
 *
 * El circuito normal es que el cliente declare el envío y el taller lo
 * autorice. Pero en la práctica llegan equipos sin nada cargado —los trae un
 * camión, los deja alguien en la puerta— y el taller no puede quedarse sin
 * poder registrarlos: si no, o no se trabajan o se trabajan fuera del sistema,
 * que es peor.
 *
 * La diferencia con el ingreso por envío es sólo el origen. Una vez creada, la
 * recepción es idéntica y recorre el mismo pipeline. `shipment_id` queda en
 * null, y eso es lo que después permite distinguir unas de otras.
 */
class RegisterDirectReception
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __invoke(Organization $organization, array $data): Reception
    {
        $asset = $this->resolveAsset($organization, $data);

        $reception = DB::transaction(fn () => Reception::create([
            'organization_id' => $organization->getKey(),
            'asset_id' => $asset->getKey(),
            // Sin envío: es lo que marca que entró por mostrador.
            'shipment_id' => null,
            'received_at' => $data['received_at'],
            'promised_at' => $data['promised_at'] ?? null,
            'is_urgent' => $data['is_urgent'] ?? false,
            'received_by' => Auth::id(),
            'notes' => $data['notes'] ?? null,
        ]));

        ActivityLogger::log(
            action: 'reception.created',
            subject: $reception,
            description: sprintf(
                'Ingreso directo de %s (%s), sin envío previo',
                $asset->displayName(),
                $asset->serial_number ?? $asset->code ?? 'sin identificación',
            ),
            organizationId: $organization->getKey(),
        );

        return $reception;
    }

    /**
     * La pieza ya registrada, o una nueva dada de alta en el momento.
     *
     * Se piden sólo los datos imprescindibles para identificarla: el equipo
     * está físicamente en el mostrador y quien lo recibe no puede quedarse
     * completando una ficha técnica entera. El resto se edita después.
     */
    private function resolveAsset(Organization $organization, array $data): Asset
    {
        if (! empty($data['asset_public_id'])) {
            $asset = Asset::query()
                ->forOrganization($organization->getKey())
                ->where('public_id', $data['asset_public_id'])
                ->first();

            if ($asset === null) {
                throw ValidationException::withMessages([
                    'asset_public_id' => 'La pieza elegida no existe o no es de esta empresa.',
                ]);
            }

            return $asset;
        }

        if (blank($data['description'] ?? null)) {
            throw ValidationException::withMessages([
                'description' => 'Elija una pieza registrada o describa la que ingresa.',
            ]);
        }

        /*
         * Un número de serie repetido dentro de la misma empresa casi siempre
         * es la misma pieza volviendo al taller, no una nueva. Darla de alta
         * otra vez partiría su historial en dos y rompería la trazabilidad.
         */
        if (! blank($data['serial_number'] ?? null)) {
            $existente = Asset::query()
                ->forOrganization($organization->getKey())
                ->where('serial_number', $data['serial_number'])
                ->first();

            if ($existente !== null) {
                throw ValidationException::withMessages([
                    'serial_number' => sprintf(
                        'Ya existe una pieza de esta empresa con ese número de serie: «%s». Elíjala del listado.',
                        $existente->displayName(),
                    ),
                ]);
            }
        }

        $asset = Asset::create([
            'organization_id' => $organization->getKey(),
            'kind' => $data['kind'] ?? Asset::KIND_PART,
            'description' => $data['description'],
            'serial_number' => $data['serial_number'] ?? null,
            'client_reference' => $data['client_reference'] ?? null,
            'code' => Str::upper(Str::random(8)),
        ]);

        ActivityLogger::log(
            action: 'asset.created',
            subject: $asset,
            description: "Alta desde el taller al recibir {$asset->displayName()}",
            organizationId: $organization->getKey(),
        );

        return $asset;
    }
}
