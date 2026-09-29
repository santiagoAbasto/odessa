import { Deferred, Head, Link } from '@inertiajs/react';
import {
  AlertTriangle,
  ArrowUpRight,
  CalendarClock,
  CheckCircle2,
  CircleDashed,
  FileSearch,
  PackageCheck,
  Truck,
  Warehouse,
  XCircle,
  type LucideIcon,
} from 'lucide-react';
import { ExpiryChart } from '@/components/charts/expiry-chart';
import { Badge } from '@/components/ui/badge';
import { Panel, PanelHeader } from '@/components/ui/panel';
import { Skeleton } from '@/components/ui/skeleton';
import { EmptyState } from '@/components/ui/empty-state';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/cn';
import { expiryLabel, formatDate, formatNumber } from '@/lib/format';
import type { ReportRow, Tone } from '@/types';

interface StatusCard {
  key: string;
  label: string;
  tone: Tone;
  count: number;
  href: string;
}

interface WorkshopStats {
  pendingOnTime: number;
  pendingOverdue: number;
  releasedNotDispatched: number;
  urgent: number;
  dispatched: number;
}

interface ExpiringAsset {
  id: string;
  name: string;
  serialNumber: string | null;
  expiresAt: string | null;
  daysLeft: number;
  kind: string;
}

interface DashboardProps {
  warningDays: number;
  statusCards: StatusCard[];
  totalAssets: number;
  expiryFlow?: { week: string; count: number }[];
  workshop?: WorkshopStats | null;
  recentReports?: ReportRow[];
  expiringSoon?: ExpiringAsset[];
}

const statusIcons: Record<string, LucideIcon> = {
  fit: CheckCircle2,
  expiring: AlertTriangle,
  unfit: XCircle,
  unknown: CircleDashed,
};

export default function Dashboard({
  warningDays,
  statusCards,
  totalAssets,
  expiryFlow,
  workshop,
  recentReports,
  expiringSoon,
}: DashboardProps) {
  return (
    <AppLayout
      title="Panel de control"
      description="Estado del equipamiento y de la operación de taller."
      breadcrumbs={[{ label: 'Panel' }]}
    >
      <Head title="Panel" />

      {/* ================= Aptitud del equipamiento ================= */}
      <section aria-labelledby="titulo-aptitud" className="mb-5">
        <h2 id="titulo-aptitud" className="sr-only">
          Aptitud del equipamiento
        </h2>

        <div className="grid gap-px overflow-hidden rounded-sm border border-[var(--border-subtle)] bg-[var(--border-subtle)] sm:grid-cols-2 lg:grid-cols-4">
          <div className="bg-[var(--surface-raised)] p-4">
            <p className="text-[11px] font-medium text-[var(--text-secondary)]">Total de activos</p>
            <p className="mt-1.5 text-[30px] leading-none font-semibold tracking-tight tabular">
              {formatNumber(totalAssets)}
            </p>
            <p className="mt-2 text-[11.5px] text-[var(--text-muted)]">Piezas y genéricos registrados</p>
          </div>

          {statusCards.map((card) => {
            const Icon = statusIcons[card.key] ?? CircleDashed;

            return (
              <Link
                key={card.key}
                href={card.href}
                className="group bg-[var(--surface-raised)] p-4 transition-colors hover:bg-[var(--surface-hover)]"
              >
                <div className="flex items-center justify-between gap-2">
                  <p className="text-[11px] font-medium text-[var(--text-secondary)]">{card.label}</p>
                  <Icon
                    className={cn(
                      'size-4',
                      card.tone === 'success' && 'text-[var(--tone-success-fg)]',
                      card.tone === 'warning' && 'text-[var(--tone-warning-fg)]',
                      card.tone === 'danger' && 'text-[var(--tone-danger-fg)]',
                      card.tone === 'neutral' && 'text-[var(--text-muted)]',
                    )}
                    aria-hidden
                  />
                </div>
                <p className="mt-1.5 text-[30px] leading-none font-semibold tracking-tight tabular">
                  {formatNumber(card.count)}
                </p>
                <p className="mt-2 inline-flex items-center gap-1 text-[11.5px] text-[var(--text-accent)] opacity-0 transition-opacity group-hover:opacity-100">
                  Ver listado
                  <ArrowUpRight className="size-3" aria-hidden />
                </p>
              </Link>
            );
          })}
        </div>
      </section>

      <div className="grid gap-5 lg:grid-cols-[1.35fr_1fr]">
        {/* ================= Flujo de vencimientos ================= */}
        <Panel>
          <PanelHeader
            title={`Vencimientos en los próximos ${warningDays} días`}
            description="Piezas aptas que perderán vigencia si no se reinspeccionan."
          />
          <Deferred data="expiryFlow" fallback={<Skeleton className="m-4 h-56" />}>
            <ExpiryChart data={expiryFlow ?? []} warningDays={warningDays} />
          </Deferred>
        </Panel>

        {/* ================= Taller ================= */}
        <Deferred data="workshop" fallback={<Panel className="h-full"><Skeleton className="m-4 h-48" /></Panel>}>
          <WorkshopPanel stats={workshop ?? null} />
        </Deferred>
      </div>

      <div className="mt-5 grid gap-5 lg:grid-cols-2">
        {/* ================= Próximos vencimientos ================= */}
        <Panel>
          <PanelHeader
            title="Próximos a vencer"
            description="Ordenados por urgencia."
            actions={
              <Link
                href="/activos?status=expiring"
                className="inline-flex items-center gap-1 text-[11.5px] font-medium text-[var(--text-accent)] hover:underline"
              >
                Ver todos
                <ArrowUpRight className="size-3" aria-hidden />
              </Link>
            }
          />
          <Deferred data="expiringSoon" fallback={<ListSkeleton />}>
            <ExpiringList assets={expiringSoon ?? []} warningDays={warningDays} />
          </Deferred>
        </Panel>

        {/* ================= FMR recientes ================= */}
        <Panel>
          <PanelHeader
            title="Informes recientes"
            description="Últimos informes registrados."
            actions={
              <Link
                href="/informes"
                className="inline-flex items-center gap-1 text-[11.5px] font-medium text-[var(--text-accent)] hover:underline"
              >
                Ver todos
                <ArrowUpRight className="size-3" aria-hidden />
              </Link>
            }
          />
          <Deferred data="recentReports" fallback={<ListSkeleton />}>
            <RecentReports reports={recentReports ?? []} />
          </Deferred>
        </Panel>
      </div>
    </AppLayout>
  );
}

function WorkshopPanel({ stats }: { stats: WorkshopStats | null }) {
  if (!stats) {
    return (
      <Panel className="h-full">
        <PanelHeader title="Taller" />
        <EmptyState
          icon={Warehouse}
          title="Sin acceso al área de taller"
          description="Su rol no incluye la vista de gestión de equipos."
        />
      </Panel>
    );
  }

  const metrics: { label: string; value: number; icon: LucideIcon; tone?: Tone; href: string }[] = [
    { label: 'Pendientes en fecha', value: stats.pendingOnTime, icon: CalendarClock, href: '/taller/recepciones?stage=pending' },
    { label: 'Pendientes vencidos', value: stats.pendingOverdue, icon: AlertTriangle, tone: 'danger', href: '/taller/recepciones?stage=pending&overdue=1' },
    { label: 'Liberados sin despachar', value: stats.releasedNotDispatched, icon: PackageCheck, href: '/taller/recepciones?stage=released' },
    { label: 'Urgentes en taller', value: stats.urgent, icon: AlertTriangle, tone: 'warning', href: '/taller/recepciones?urgent=1' },
    { label: 'Despachados', value: stats.dispatched, icon: Truck, href: '/taller/recepciones?stage=dispatched' },
  ];

  return (
    <Panel className="h-full">
      <PanelHeader title="Taller" description="Pipeline de gestión de equipos." />
      <ul className="divide-y divide-[var(--border-subtle)]">
        {metrics.map((metric) => (
          <li key={metric.label}>
            <Link
              href={metric.href}
              className="flex items-center gap-3 px-4 py-3 transition-colors hover:bg-[var(--surface-hover)]"
            >
              <metric.icon
                className={cn(
                  'size-4 shrink-0',
                  metric.tone === 'danger'
                    ? 'text-[var(--tone-danger-fg)]'
                    : metric.tone === 'warning'
                      ? 'text-[var(--tone-warning-fg)]'
                      : 'text-[var(--text-muted)]',
                )}
                aria-hidden
              />
              <span className="flex-1 text-[12.5px]">{metric.label}</span>
              <span className="text-[16px] font-semibold tabular">{formatNumber(metric.value)}</span>
            </Link>
          </li>
        ))}
      </ul>
    </Panel>
  );
}

function ExpiringList({ assets, warningDays }: { assets: ExpiringAsset[]; warningDays: number }) {
  if (assets.length === 0) {
    return (
      <EmptyState
        icon={CheckCircle2}
        title="Nada por vencer"
        description={`Ninguna pieza pierde vigencia en los próximos ${warningDays} días.`}
      />
    );
  }

  return (
    <ul className="divide-y divide-[var(--border-subtle)]">
      {assets.map((asset) => (
        <li key={asset.id}>
          <Link
            href={`/activos/${asset.id}`}
            className="flex items-center gap-3 px-4 py-2.5 transition-colors hover:bg-[var(--surface-hover)]"
          >
            <div className="min-w-0 flex-1">
              <p className="truncate text-[12.5px] font-medium">{asset.name}</p>
              <p className="mt-0.5 truncate font-mono text-[11px] text-[var(--text-muted)]">
                {asset.serialNumber ?? 'Sin número de serie'}
              </p>
            </div>
            <div className="shrink-0 text-right">
              <p className="text-[12px] tabular">{formatDate(asset.expiresAt)}</p>
              <p
                className={cn(
                  'mt-0.5 text-[11px]',
                  asset.daysLeft <= 15
                    ? 'text-[var(--tone-danger-fg)]'
                    : 'text-[var(--tone-warning-fg)]',
                )}
              >
                {expiryLabel(asset.expiresAt)}
              </p>
            </div>
          </Link>
        </li>
      ))}
    </ul>
  );
}

function RecentReports({ reports }: { reports: ReportRow[] }) {
  if (reports.length === 0) {
    return (
      <EmptyState
        icon={FileSearch}
        title="Sin informes registrados"
        description="Los informes aparecerán aquí en cuanto se carguen."
      />
    );
  }

  return (
    <ul className="divide-y divide-[var(--border-subtle)]">
      {reports.map((report) => (
        <li key={report.id}>
          <Link
            href={`/informes/${report.id}`}
            className="flex items-center gap-3 px-4 py-2.5 transition-colors hover:bg-[var(--surface-hover)]"
          >
            <span className="grid size-8 shrink-0 place-items-center rounded-xs bg-[var(--accent-soft)] font-mono text-[10px] font-semibold text-[var(--text-accent)]">
              Informe
            </span>
            <div className="min-w-0 flex-1">
              <p className="truncate text-[12.5px] font-medium">
                {report.number}
                {report.equipment && (
                  <span className="ml-2 font-normal text-[var(--text-secondary)]">
                    {report.equipment}
                  </span>
                )}
              </p>
              <p className="mt-0.5 text-[11px] text-[var(--text-muted)]">
                {formatDate(report.inspectedAt)} · {report.assetsCount}{' '}
                {report.assetsCount === 1 ? 'pieza' : 'piezas'}
              </p>
            </div>
            <Badge tone={report.statusTone}>{report.statusLabel}</Badge>
          </Link>
        </li>
      ))}
    </ul>
  );
}

function ListSkeleton() {
  return (
    <div className="divide-y divide-[var(--border-subtle)]">
      {Array.from({ length: 5 }).map((_, index) => (
        <div key={index} className="flex items-center gap-3 px-4 py-3">
          <Skeleton className="size-8 shrink-0" />
          <div className="flex-1 space-y-1.5">
            <Skeleton className="h-3 w-2/5" />
            <Skeleton className="h-2.5 w-1/4" />
          </div>
          <Skeleton className="h-5 w-16" />
        </div>
      ))}
    </div>
  );
}
