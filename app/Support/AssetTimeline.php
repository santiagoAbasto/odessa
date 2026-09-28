<?php

namespace App\Support;

use App\Models\ActivityLog;
use App\Models\Asset;
use Illuminate\Support\Collection;

/**
 * Hilo de trazabilidad de una pieza.
 *
 * Reúne en una sola línea de tiempo todo lo que le pasó al equipo, sin
 * importar de qué tabla salga: envíos, recepciones, hitos del taller,
 * inspecciones, documentos y accesos registrados en la auditoría.
 *
 * El negocio lo pidió así de explícito: por cada equipo hay que poder ver
 * **quién lo hizo, qué hizo y cuándo**, sin que se escape nada. Estando la
 * información repartida en cinco tablas, nadie va a reconstruir eso a mano;
 * o el sistema lo muestra junto o en la práctica no existe.
 *
 * Cada hito lleva además `recordedAt`, que es cuándo quedó registrado en el
 * sistema. No siempre coincide con cuándo ocurrió —un FMR se carga días
 * después de la inspección— y esa diferencia es justamente lo que se audita.
 */
class AssetTimeline
{
    public function __construct(private Asset $asset) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function build(): array
    {
        return collect()
            ->concat($this->shipments())
            ->concat($this->receptions())
            ->concat($this->inspections())
            ->concat($this->documents())
            ->concat($this->auditTrail())
            // Del más reciente al más antiguo: lo último que pasó es lo que
            // se está buscando el 90% de las veces.
            ->sortByDesc(fn (array $event) => $event['at'] ?? '')
            ->values()
            ->all();
    }

    /** El cliente declaró que lo enviaba. */
    private function shipments(): Collection
    {
        return $this->asset->shipmentItems()
            ->with(['shipment.creator:id,name', 'shipment.reviewer:id,name'])
            ->get()
            ->flatMap(function ($item) {
                $shipment = $item->shipment;

                if ($shipment === null) {
                    return [];
                }

                $events = [[
                    'kind' => 'shipment',
                    'icon' => 'send',
                    'tone' => 'neutral',
                    'title' => 'Declarado en un envío al taller',
                    'detail' => sprintf(
                        'Envío %s%s',
                        $shipment->reference,
                        $shipment->client_reference ? " · remito {$shipment->client_reference}" : '',
                    ),
                    'by' => $shipment->creator?->name,
                    'at' => $shipment->created_at?->toIso8601String(),
                    'recordedAt' => $shipment->created_at?->toIso8601String(),
                    'href' => "/envios/{$shipment->public_id}",
                ]];

                if ($shipment->reviewed_at !== null) {
                    $authorized = $shipment->status === 'authorized';

                    $events[] = [
                        'kind' => 'shipment',
                        'icon' => $authorized ? 'check' : 'x',
                        'tone' => $authorized ? 'success' : 'danger',
                        'title' => $authorized ? 'Envío autorizado por el taller' : 'Envío rechazado',
                        'detail' => $shipment->review_notes ?: "Envío {$shipment->reference}",
                        'by' => $shipment->reviewer?->name,
                        'at' => $shipment->reviewed_at->toIso8601String(),
                        'recordedAt' => $shipment->reviewed_at->toIso8601String(),
                        'href' => "/envios/{$shipment->public_id}",
                    ];
                }

                return $events;
            });
    }

    /** Ingreso al taller y los hitos del pipeline. */
    private function receptions(): Collection
    {
        return $this->asset->receptions()
            ->with([
                'receiver:id,name', 'releaser:id,name', 'dispatcher:id,name',
                'startedBy:id,name', 'repairStartedBy:id,name', 'repairedBy:id,name',
                'repairRequestedBy:id,name', 'repairDecidedBy:id,name',
                'repairFailedBy:id,name', 'unrepairableBy:id,name',
            ])
            ->get()
            ->flatMap(function ($reception) {
                $events = [[
                    'kind' => 'reception',
                    'icon' => 'inbox',
                    'tone' => 'neutral',
                    'title' => 'Recibido en el taller',
                    'detail' => $reception->is_urgent ? 'Ingreso urgente' : 'Ingreso al taller',
                    'by' => $reception->receiver?->name,
                    'at' => $reception->received_at?->toIso8601String(),
                    'recordedAt' => $reception->created_at?->toIso8601String(),
                    'href' => $reception->url(),
                ]];

                /*
                 * El dictamen cambia de título y de tono según cómo haya
                 * salido: un rechazo tiene que leerse distinto de una
                 * aprobación, no como "resuelto" a secas.
                 */
                $rechazado = $reception->wasRejected();

                $milestones = [
                    ['started_at', 'startedBy', 'play', 'warning', 'Se empezó a trabajar', null],
                    [
                        'released_at', 'releaser',
                        $rechazado ? 'x' : 'package-check',
                        $rechazado ? 'danger' : 'info',
                        $rechazado ? 'Trabajo rechazado' : 'Trabajo aprobado',
                        $reception->outcome_notes,
                    ],
                    [
                        'repair_requested_at', 'repairRequestedBy', 'message-square-quote', 'warning',
                        'Se consultó al cliente si autoriza la reparación',
                        $reception->repair_request_notes,
                    ],
                    /*
                     * La respuesta del cliente es el hito que decide el final
                     * del recorrido, y además es lo que respalda el cobro de
                     * la reparación. Se anota por qué vía llegó: no es lo
                     * mismo que la haya cargado el cliente que que la haya
                     * transcripto Odessa.
                     */
                    [
                        'repair_decided_at', 'repairDecidedBy',
                        $reception->repairWasDeclined() ? 'x' : 'package-check',
                        $reception->repairWasDeclined() ? 'danger' : 'info',
                        $reception->repairWasDeclined()
                            ? 'El cliente no autorizó la reparación'
                            : 'El cliente autorizó la reparación',
                        trim(sprintf(
                            '%s%s',
                            $reception->decisionChannelLabel() ? "Vía: {$reception->decisionChannelLabel()}. " : '',
                            (string) $reception->repair_decision_notes,
                        )) ?: null,
                    ],
                    ['repair_started_at', 'repairStartedBy', 'wrench', 'warning', 'Se inició la reparación', null],
                    ['repaired_at', 'repairedBy', 'package-check', 'success', 'Reparación aprobada', null],
                    ['repair_failed_at', 'repairFailedBy', 'x', 'danger', 'Reparación rechazada', $reception->repair_failure_notes],
                    ['unrepairable_at', 'unrepairableBy', 'x', 'danger', 'Marcado sin solución', $reception->unrepairable_notes],
                    [
                        'dispatched_at', 'dispatcher', 'truck',
                        // Devuelto sin reparar no es un cierre feliz: el equipo
                        // se fue no apto y el hilo tiene que decirlo.
                        $reception->leavesUnrepaired() ? 'danger' : 'success',
                        $reception->leavesUnrepaired()
                            ? 'Devuelto al cliente sin reparar'
                            : 'Despachado al cliente',
                        $reception->dispatch_note ? "Remito {$reception->dispatch_note}" : null,
                    ],
                ];

                foreach ($milestones as [$column, $relation, $icon, $tone, $title, $detail]) {
                    if ($reception->{$column} === null) {
                        continue;
                    }

                    $events[] = [
                        'kind' => 'reception',
                        'icon' => $icon,
                        'tone' => $tone,
                        'title' => $title,
                        'detail' => $detail,
                        'by' => $reception->{$relation}?->name,
                        'at' => $reception->{$column}->toIso8601String(),
                        'recordedAt' => $reception->{$column}->toIso8601String(),
                        'href' => $reception->url(),
                    ];
                }

                return $events;
            });
    }

    /** Dictámenes. Se incluyen los que no alteran el estado: también son trazabilidad. */
    private function inspections(): Collection
    {
        return $this->asset->inspectionLines()
            ->with(['inspectionReport:id,public_id,number,inspected_at,status,created_at', 'service:id,code,name'])
            ->get()
            ->map(function ($line) {
                $report = $line->inspectionReport;

                return [
                    'kind' => 'inspection',
                    'icon' => 'clipboard-check',
                    'tone' => $line->result->tone(),
                    'title' => sprintf('Inspección: %s', $line->result->label()),
                    'detail' => trim(sprintf(
                        '%s%s%s',
                        $line->service?->label() ? $line->service->label().' · ' : '',
                        "FMR {$report?->number}",
                        $line->expires_at ? ' · vence '.$line->expires_at->format('d/m/Y') : '',
                    )),
                    'note' => $line->findings,
                    /*
                     * Sólo los dictámenes Apto/No Apto de un FMR final fijan el
                     * estado. Marcarlo en el hilo evita la confusión de ver un
                     * "No aplica" reciente y creer que cambió algo.
                     */
                    'determinesFitness' => $line->result->determinesFitness()
                        && $report?->status->value === 'final',
                    'by' => null,
                    'at' => $report?->inspected_at?->toIso8601String(),
                    'recordedAt' => $report?->created_at?->toIso8601String(),
                    'href' => $report ? "/informes/{$report->public_id}" : null,
                ];
            });
    }

    private function documents(): Collection
    {
        return $this->asset->documents()
            ->with('uploader:id,name')
            ->get()
            ->map(fn ($document) => [
                'kind' => 'document',
                'icon' => 'paperclip',
                'tone' => 'neutral',
                'title' => 'Archivo adjuntado',
                'detail' => $document->original_name,
                'by' => $document->uploader?->name,
                'at' => $document->created_at?->toIso8601String(),
                'recordedAt' => $document->created_at?->toIso8601String(),
                'href' => "/documentos/{$document->public_id}/descargar",
            ]);
    }

    /**
     * Acciones registradas sobre esta pieza en la auditoría.
     *
     * Es lo que completa el "no se escapa nada": quién la consultó, quién
     * editó sus datos, quién descargó su documentación.
     */
    private function auditTrail(): Collection
    {
        return ActivityLog::query()
            ->with('user:id,name')
            ->where('subject_type', Asset::class)
            ->where('subject_id', $this->asset->getKey())
            ->orderByDesc('created_at')
            ->limit(200)
            ->get()
            ->map(fn (ActivityLog $log) => [
                'kind' => 'audit',
                'icon' => 'eye',
                'tone' => AuditActions::tone($log->action),
                'title' => AuditActions::label($log->action),
                'detail' => $log->description,
                'by' => $log->user?->name ?? 'Sistema',
                'ip' => $log->ip_address,
                'at' => $log->created_at?->toIso8601String(),
                'recordedAt' => $log->created_at?->toIso8601String(),
                'href' => null,
            ]);
    }
}
