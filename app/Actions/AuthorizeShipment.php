<?php

namespace App\Actions;

use App\Models\Asset;
use App\Models\Organization;
use App\Models\Reception;
use App\Models\Shipment;
use App\Models\User;
use App\Notifications\ShipmentItemsNotArrived;
use App\Support\ActivityLogger;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification as Notifier;
use Illuminate\Validation\ValidationException;

/**
 * El taller da recepción a un envío pre-cargado por el cliente.
 *
 * Dar recepción es el momento del ingreso: hasta entonces el envío es sólo una
 * declaración. El taller controla equipo por equipo lo que realmente llegó.
 *
 *  - De lo que llegó: las piezas que todavía no existen se dan de alta en el
 *    inventario de esa organización, y se crea una recepción por cada una, que
 *    entra al pipeline del taller.
 *  - De lo que NO llegó: la línea queda marcada con su motivo, no se ingresa
 *    nada al taller, y se le avisa al operador por correo.
 *
 * Si no llegó ninguno, el envío queda rechazado. Todo dentro de una
 * transacción, y registrado en la auditoría.
 */
class AuthorizeShipment
{
    public function __construct(private CreateAsset $createAsset) {}

    /**
     * @param  list<int>  $arrivedItemIds  ids de las líneas que efectivamente llegaron
     * @param  array<int, string>  $missingReasons  motivo por id de línea que no llegó
     */
    public function receive(
        Shipment $shipment,
        array $arrivedItemIds,
        array $missingReasons = [],
        ?string $notes = null,
        ?string $promisedAt = null,
    ): Shipment {
        if (! $shipment->isPending()) {
            throw ValidationException::withMessages([
                'status' => 'Sólo se puede dar recepción a los envíos pendientes de recepción.',
            ]);
        }

        $arrived = array_map('intval', $arrivedItemIds);

        /** @var list<array{name: string, serial: ?string, reason: string}> $missing */
        $missing = [];

        DB::transaction(function () use ($shipment, $arrived, $missingReasons, $notes, $promisedAt, &$missing) {
            $shipment->loadMissing('items.asset');

            foreach ($shipment->items as $item) {
                if (! in_array($item->id, $arrived, true)) {
                    // No llegó: queda registrado con su motivo y no entra al taller.
                    $item->forceFill([
                        'not_arrived_at' => now(),
                        'not_arrived_reason' => $missingReasons[$item->id] ?? 'No llegó al taller.',
                    ])->save();

                    $missing[] = [
                        'name' => $item->displayName(),
                        'serial' => $item->displaySerial(),
                        'reason' => $item->not_arrived_reason,
                    ];

                    continue;
                }

                $asset = $item->asset;

                if ($asset === null) {
                    /*
                     * Alta de la pieza que el cliente declaró sin registrar.
                     * Pasa por CreateAsset para que la comprobación de serie
                     * repetida valga también acá.
                     */
                    $asset = ($this->createAsset)(
                        $shipment->organization,
                        [
                            'kind' => Asset::KIND_PART,
                            'description' => $item->declared_description,
                            'serial_number' => $item->declared_serial,
                        ],
                        "Alta al dar recepción al envío {$shipment->reference}",
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

            // Si no llegó nada, el envío queda rechazado; si llegó algo, recibido.
            $shipment->forceFill([
                'status' => count($arrived) > 0 ? Shipment::STATUS_AUTHORIZED : Shipment::STATUS_REJECTED,
                'reviewed_by' => Auth::id(),
                'reviewed_at' => now(),
                'review_notes' => $notes,
            ])->save();
        });

        // El aviso al operador va fuera de la transacción: es un efecto externo,
        // no debe poder tumbar el ingreso si el correo falla.
        if ($missing !== []) {
            $this->notifyOperator($shipment, $missing);
        }

        $recibidas = count($arrived);
        ActivityLogger::log(
            action: $recibidas > 0 ? 'shipment.authorized' : 'shipment.rejected',
            subject: $shipment,
            description: $recibidas > 0
                ? "Envío {$shipment->reference} recibido; {$recibidas} ingresadas, ".count($missing).' no llegaron'
                : "Envío {$shipment->reference} rechazado: no llegó ningún equipo",
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

    /**
     * Avisa a la empresa que mandó el envío qué equipos no llegaron. Va a los
     * usuarios activos de esa empresa —el operador que lo cargó está entre
     * ellos— y a los correos de contacto de la ficha que no sean ya de un
     * usuario.
     *
     * @param  list<array{name: string, serial: ?string, reason: string}>  $missing
     */
    private function notifyOperator(Shipment $shipment, array $missing): void
    {
        $notification = new ShipmentItemsNotArrived($shipment, $missing);

        $users = User::withoutGlobalScope('organization')
            ->where('organization_id', $shipment->organization_id)
            ->where('is_active', true)
            ->get();

        if ($users->isNotEmpty()) {
            Notifier::send($users, $notification);
        }

        $contacts = Organization::splitEmails(
            Organization::query()->whereKey($shipment->organization_id)->value('contact_email')
        );

        foreach ($contacts as $contact) {
            if (! $users->contains(fn (User $u) => strcasecmp($u->email, $contact) === 0)) {
                Notifier::route('mail', $contact)->notify($notification);
            }
        }
    }
}
