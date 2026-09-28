<?php

namespace App\Actions;

use App\Models\Asset;
use App\Models\Reception;
use App\Models\Shipment;
use App\Support\ActivityLogger;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * El taller autoriza o rechaza un envío pre-cargado por el cliente.
 *
 * Autorizar es el momento del ingreso: hasta entonces el envío es sólo una
 * declaración. Al autorizar, y dentro de una transacción:
 *
 *  - las piezas declaradas que aún no existen se dan de alta en el inventario
 *    de esa organización (el alta la decide el taller, no el cliente);
 *  - se crea una recepción por cada línea, que entra al pipeline del taller;
 *  - queda registrado en la auditoría.
 */
class AuthorizeShipment
{
    public function __construct(private CreateAsset $createAsset) {}

    public function authorize(Shipment $shipment, ?string $notes = null, ?string $promisedAt = null): Shipment
    {
        if (! $shipment->isPending()) {
            throw ValidationException::withMessages([
                'status' => 'Sólo se puede dar recepción a los envíos pendientes de recepción.',
            ]);
        }

        DB::transaction(function () use ($shipment, $notes, $promisedAt) {
            $shipment->loadMissing('items.asset');

            foreach ($shipment->items as $item) {
                $asset = $item->asset;

                if ($asset === null) {
                    /*
                     * Alta de la pieza que el cliente declaró sin registrar.
                     * Pasa por CreateAsset para que la comprobación de serie
                     * repetida valga también acá: antes este camino creaba el
                     * registro directo y podía duplicar una pieza existente.
                     */
                    $asset = ($this->createAsset)(
                        $shipment->organization,
                        [
                            'kind' => Asset::KIND_PART,
                            'description' => $item->declared_description,
                            'serial_number' => $item->declared_serial,
                        ],
                        "Alta al autorizar el envío {$shipment->reference}",
                    );

                    $item->asset()->associate($asset);
                }

                $reception = Reception::create([
                    'organization_id' => $shipment->organization_id,
                    'asset_id' => $asset->getKey(),
                    'shipment_id' => $shipment->getKey(),
                    'received_at' => today(),
                    'promised_at' => $promisedAt,
                    'is_urgent' => $item->is_urgent,
                    'received_by' => Auth::id(),
                    'notes' => $item->notes,
                ]);

                $item->reception()->associate($reception);
                $item->save();
            }

            $shipment->forceFill([
                'status' => Shipment::STATUS_AUTHORIZED,
                'reviewed_by' => Auth::id(),
                'reviewed_at' => now(),
                'review_notes' => $notes,
            ])->save();
        });

        ActivityLogger::log(
            action: 'shipment.authorized',
            subject: $shipment,
            description: "Envío {$shipment->reference} recibido; se crearon {$shipment->items()->count()} recepciones",
            organizationId: $shipment->organization_id,
        );

        return $shipment->refresh();
    }

    public function reject(Shipment $shipment, string $reason): Shipment
    {
        if (! $shipment->isPending()) {
            throw ValidationException::withMessages([
                'status' => 'Sólo se pueden rechazar los envíos pendientes de recepción.',
            ]);
        }

        $shipment->forceFill([
            'status' => Shipment::STATUS_REJECTED,
            'reviewed_by' => Auth::id(),
            'reviewed_at' => now(),
            'review_notes' => $reason,
        ])->save();

        ActivityLogger::log(
            action: 'shipment.rejected',
            subject: $shipment,
            description: "Envío {$shipment->reference} rechazado",
            organizationId: $shipment->organization_id,
        );

        return $shipment;
    }
}
