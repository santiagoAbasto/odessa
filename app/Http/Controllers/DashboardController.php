<?php

namespace App\Http\Controllers;

use App\Enums\AssetStatus;
use App\Models\Asset;
use App\Models\InspectionReport;
use App\Models\Reception;
use App\Support\Permissions;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Panel principal.
 *
 * Los indicadores replican los que el sistema auditado ya calculaba
 * (aptitud de piezas, vencimientos a 60 días, pipeline de recepción) porque
 * son los que el negocio realmente mira. Cada tarjeta enlaza al listado
 * filtrado correspondiente, algo que el sistema original sólo hacía a medias.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        $warningDays = (int) config('odessa.expiry_warning_days');

        $statusCounts = Asset::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $cards = collect(AssetStatus::cases())
            ->reject(fn (AssetStatus $s) => $s === AssetStatus::Unknown && ($statusCounts[$s->value] ?? 0) === 0)
            ->map(fn (AssetStatus $s) => [
                'key' => $s->value,
                'label' => $s->label(),
                'tone' => $s->tone(),
                'count' => (int) ($statusCounts[$s->value] ?? 0),
                'href' => route('assets.index', ['status' => $s->value]),
            ])
            ->values()
            ->all();

        return Inertia::render('dashboard', [
            'warningDays' => $warningDays,
            'statusCards' => $cards,
            'totalAssets' => (int) $statusCounts->sum(),

            // Diferidos: el panel pinta de inmediato y los agregados llegan después.
            'expiryFlow' => Inertia::defer(fn () => $this->expiryFlow($warningDays)),
            'workshop' => Inertia::defer(fn () => $user->can(Permissions::RECEPTIONS_VIEW) ? $this->workshopStats() : null),
            'recentReports' => Inertia::defer(fn () => $this->recentReports()),
            'expiringSoon' => Inertia::defer(fn () => $this->expiringSoon($warningDays)),
        ]);
    }

    /** Piezas que vencen por semana dentro de la ventana preventiva. */
    private function expiryFlow(int $days): array
    {
        $rows = Asset::query()
            ->expiringWithin($days)
            ->whereIn('status', [AssetStatus::Fit->value, AssetStatus::Expiring->value])
            ->orderBy('expires_at')
            ->get(['expires_at'])
            ->groupBy(fn ($a) => $a->expires_at->startOfWeek()->toDateString())
            ->map->count();

        return $rows->map(fn (int $count, string $week) => [
            'week' => $week,
            'count' => $count,
        ])->values()->all();
    }

    private function workshopStats(): array
    {
        $base = fn () => Reception::query();

        return [
            // Trabajo abierto en plazo: empezado o no, lo que importa acá es
            // que todavía no salió y la fecha prometida no venció.
            'pendingOnTime' => (clone $base())->open()
                ->where(fn ($q) => $q->whereNull('promised_at')->orWhereDate('promised_at', '>=', today()))
                ->count(),
            'pendingOverdue' => (clone $base())->overdue()->count(),
            'releasedNotDispatched' => (clone $base())->whereNotNull('released_at')->whereNull('dispatched_at')->count(),
            'urgent' => (clone $base())->inWorkshop()->where('is_urgent', true)->count(),
            'dispatched' => (clone $base())->dispatched()->count(),
        ];
    }

    private function recentReports(): array
    {
        return InspectionReport::query()
            ->with('assetSet:id,name')
            ->withCount('assets')
            ->orderByDesc('inspected_at')
            ->orderByDesc('id')
            ->limit(8)
            ->get()
            ->map(fn (InspectionReport $r) => [
                'id' => $r->public_id,
                'number' => $r->number,
                'equipment' => $r->equipmentName(),
                'inspectedAt' => $r->inspected_at?->toDateString(),
                'assetsCount' => $r->assets_count,
                'status' => $r->status->value,
                'statusLabel' => $r->status->label(),
                'statusTone' => $r->status->tone(),
            ])
            ->all();
    }

    private function expiringSoon(int $days): array
    {
        return Asset::query()
            ->expiringWithin($days)
            ->whereIn('status', [AssetStatus::Fit->value, AssetStatus::Expiring->value])
            ->with('catalogItem:id,description')
            ->orderBy('expires_at')
            ->limit(10)
            ->get()
            ->map(fn (Asset $a) => [
                'id' => $a->public_id,
                'name' => $a->displayName(),
                'serialNumber' => $a->serial_number,
                'expiresAt' => $a->expires_at?->toDateString(),
                'daysLeft' => (int) today()->diffInDays($a->expires_at, false),
                'kind' => $a->kind,
            ])
            ->all();
    }
}
