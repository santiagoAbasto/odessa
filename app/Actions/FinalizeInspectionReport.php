<?php

namespace App\Actions;

use App\Enums\ReportStatus;
use App\Models\InspectionReport;
use App\Models\Organization;
use App\Models\Service;
use App\Support\ActivityLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cierra un FMR y propaga sus efectos.
 *
 * Al finalizar:
 *  - se calcula el vencimiento de cada línea con dictamen (fecha FMR + vigencia
 *    de la operación para ese cliente, o la del catálogo, o la vigencia por
 *    defecto) si no se fijó a mano;
 *  - se recalcula el estado de cada pieza afectada;
 *  - queda registrado en auditoría.
 *
 * Todo dentro de una transacción: o se finaliza entero, o no se finaliza.
 */
class FinalizeInspectionReport
{
    public function __construct(private RecalculateAssetStatus $recalculate) {}

    public function __invoke(InspectionReport $report): InspectionReport
    {
        if (! $report->status->canTransitionTo(ReportStatus::Final)) {
            throw ValidationException::withMessages([
                'status' => "Un FMR en estado «{$report->status->label()}» no puede finalizarse.",
            ]);
        }

        if ($report->lines()->count() === 0) {
            throw ValidationException::withMessages([
                'lines' => 'No se puede finalizar un FMR sin piezas asociadas.',
            ]);
        }

        $previous = $report->status;

        DB::transaction(function () use ($report) {
            $defaultValidity = (int) config('odessa.default_validity_days');

            // La misma operación puede tener otra vigencia para este cliente:
            // la define Odessa por empresa, y pesa más que la del catálogo.
            $overrides = Organization::query()->find($report->organization_id)?->validityOverrides() ?? [];
            $validity = fn (?Service $service) => $service === null
                ? null
                : ($overrides[$service->getKey()] ?? $service->validity_days);

            $report->loadMissing('lines.service', 'service');

            foreach ($report->lines as $line) {
                if (! $line->result->determinesFitness()) {
                    continue;
                }

                if ($line->expires_at === null) {
                    $days = $validity($line->service)
                        ?? $validity($report->service)
                        ?? $defaultValidity;

                    $line->expires_at = Carbon::parse($report->inspected_at)->addDays($days);
                    $line->save();
                }
            }

            $report->forceFill([
                'status' => ReportStatus::Final,
                'finalized_at' => now(),
                'finalized_by' => Auth::id(),
            ])->save();

            foreach ($report->assets()->withoutGlobalScope('organization')->get() as $asset) {
                ($this->recalculate)($asset);
            }
        });

        ActivityLogger::log(
            action: 'inspection_report.finalized',
            subject: $report,
            description: "FMR {$report->number} finalizado",
            old: ['status' => $previous->value],
            new: ['status' => ReportStatus::Final->value],
        );

        return $report->refresh();
    }
}
