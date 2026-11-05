<?php

namespace App\Actions;

use App\Enums\InspectionResult;
use App\Enums\ReportStatus;
use App\Models\Asset;
use App\Models\AssetExternalCertification;
use App\Models\Service;
use App\Support\ActivityLogger;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Carga a mano, en la ficha del equipo, la certificación que ya traía de otra
 * empresa (pedido del 05/11/2026).
 *
 * No genera un informe: Odessa no certificó nada, sólo registra el dato para que
 * el cliente tenga trazabilidad de vencimientos. Cuenta para el estado y la
 * fecha de «mandar antes de» como cualquier certificación apta, y queda en la
 * auditoría con quién la cargó y qué cambió.
 *
 * Reglas:
 *  - sólo operaciones que certifican y que Odessa le habilitó a la empresa del
 *    equipo;
 *  - sólo donde Odessa todavía no certificó esa operación: ahí rige su informe,
 *    y permitir pisarlo dejaría a un usuario cliente reescribiendo un dictamen;
 *  - una por equipo y operación: cargarla de nuevo la edita.
 */
class ManageExternalCertification
{
    public function __construct(private RecalculateAssetStatus $recalculate) {}

    /**
     * @param  array{service_id: int, certified_by: string, certificate_number?: ?string, certified_at: string, expires_at: string}  $data
     */
    public function save(Asset $asset, array $data): AssetExternalCertification
    {
        $service = Service::query()
            ->whereKey($data['service_id'])
            ->where('is_active', true)
            ->where('determines_fitness', true)
            ->whereIn('id', DB::table('organization_service')
                ->where('organization_id', $asset->organization_id)
                ->select('service_id'))
            ->first();

        if ($service === null) {
            throw ValidationException::withMessages([
                'service_id' => 'Esa operación no certifica o no está habilitada para la empresa de este equipo.',
            ]);
        }

        if ($this->odessaCertified($asset, $service)) {
            throw ValidationException::withMessages([
                'service_id' => "Odessa ya certificó {$service->code} en este equipo: rige su informe, no se carga a mano.",
            ]);
        }

        $actual = AssetExternalCertification::query()
            ->where('asset_id', $asset->getKey())
            ->where('service_id', $service->getKey())
            ->first();

        $antes = $actual ? $this->snapshot($actual, $service) : null;

        $certificacion = DB::transaction(function () use ($asset, $service, $data, $actual) {
            $certificacion = $actual ?? new AssetExternalCertification([
                'asset_id' => $asset->getKey(),
                'service_id' => $service->getKey(),
                'created_by' => Auth::id(),
            ]);

            $certificacion->fill([
                'certified_by' => trim($data['certified_by']),
                'certificate_number' => filled($data['certificate_number'] ?? null) ? trim($data['certificate_number']) : null,
                'certified_at' => $data['certified_at'],
                'expires_at' => $data['expires_at'],
                'updated_by' => Auth::id(),
            ])->save();

            // El estado y el «mandar antes de» se derivan: acá no se asignan.
            ($this->recalculate)($asset);

            return $certificacion;
        });

        ActivityLogger::log(
            action: 'asset.external_certification_saved',
            subject: $asset,
            description: ($actual ? 'Se editó' : 'Se cargó a mano')
                ." la certificación {$service->code} de otra empresa ({$certificacion->certified_by}) en la ficha de {$asset->displayName()}."
                .' No es un informe de Odessa.',
            old: $antes,
            new: $this->snapshot($certificacion, $service),
        );

        return $certificacion;
    }

    public function remove(Asset $asset, AssetExternalCertification $certificacion): void
    {
        $service = Service::query()->find($certificacion->service_id);
        $antes = $this->snapshot($certificacion, $service);

        DB::transaction(function () use ($asset, $certificacion) {
            $certificacion->delete();

            ($this->recalculate)($asset);
        });

        ActivityLogger::log(
            action: 'asset.external_certification_removed',
            subject: $asset,
            description: "Se quitó la certificación {$service?->code} de otra empresa cargada a mano en la ficha de {$asset->displayName()}.",
            old: $antes,
        );
    }

    /** ¿Tiene el equipo un dictamen final de Odessa para esa operación? */
    private function odessaCertified(Asset $asset, Service $service): bool
    {
        return DB::table('inspection_report_asset as l')
            ->join('inspection_reports as r', 'r.id', '=', 'l.inspection_report_id')
            ->where('l.asset_id', $asset->getKey())
            ->whereIn('l.result', [InspectionResult::Apto->value, InspectionResult::NoApto->value])
            ->where('r.status', ReportStatus::Final->value)
            ->whereNull('r.deleted_at')
            // Vale la operación de la línea; si no tiene, la del informe.
            ->whereRaw('coalesce(l.service_id, r.service_id) = ?', [$service->getKey()])
            ->exists();
    }

    /** @return array<string, ?string> */
    private function snapshot(AssetExternalCertification $c, ?Service $service): array
    {
        return [
            'operation' => $service?->code,
            'certified_by' => $c->certified_by,
            'certificate_number' => $c->certificate_number,
            'certified_at' => $c->certified_at?->toDateString(),
            'expires_at' => $c->expires_at?->toDateString(),
        ];
    }
}
