import { Head, Link, router } from '@inertiajs/react';
import { AlertTriangle, BarChart3, Download } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { Input, Select } from '@/components/ui/input';
import { Panel, PanelHeader } from '@/components/ui/panel';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/cn';
import { formatDate, formatNumber } from '@/lib/format';
import type { SelectOption, Tone } from '@/types';

interface Report {
  status: {
    total: number;
    counts: Record<string, number>;
    labels: Record<string, { label: string; tone: Tone }>;
  };
  expiry: {
    windows: Record<string, number>;
    sendDeadlineMissed: number;
    items: {
      id: string;
      name: string;
      serialNumber: string | null;
      organization: string | null;
      statusLabel: string;
      statusTone: Tone;
      expiresAt: string | null;
      sendByAt: string | null;
      daysLeft: number;
      missed: boolean;
    }[];
  };
  work: {
    now: Record<'in_workshop' | 'pending' | 'in_progress' | 'rejected' | 'awaiting_client' | 'repair' | 'ready', number>;
    period: { received: number; dispatched: number; returnedUnrepaired: number; reportsIssued: number };
    receptions: {
      id: string;
      url: string;
      asset: string | null;
      serialNumber: string | null;
      organization: string | null;
      receivedAt: string | null;
      dispatchedAt: string | null;
      stageLabel: string;
      stageTone: Tone;
    }[];
    reports: {
      id: string;
      number: string;
      inspectedAt: string | null;
      equipment: string | null;
      assetsCount: number;
      organization: string | null;
    }[];
  };
}

/**
 * Reporte por organización: status, vencimientos y trabajos.
 *
 * Lo mismo que baja el Excel, en pantalla. Un cliente ve lo suyo; el personal
 * de Odessa elige la empresa o ve todas juntas.
 */
export default function OrganizationReport({
  report,
  filters,
  organizationOptions,
  can,
}: {
  report: Report;
  filters: { organization: string | null; desde: string; hasta: string };
  organizationOptions: SelectOption[];
  can: { export: boolean };
}) {
  const showOrganization = organizationOptions.length > 0 && !filters.organization;
  const query = Object.fromEntries(Object.entries(filters).filter(([, value]) => value)) as Record<string, string>;

  const apply = (patch: Partial<typeof filters>) =>
    router.get('/reportes', { ...query, ...patch }, { preserveState: true, preserveScroll: true, replace: true });

  return (
    <AppLayout
      title="Reportes"
      description="Status de los equipos, vencimientos y trabajos del taller, por organización."
      breadcrumbs={[{ label: 'Operación' }, { label: 'Reportes' }]}
      actions={
        can.export && (
          <Button asChild variant="primary" size="sm">
            <a href={`/reportes/exportar?${new URLSearchParams(query).toString()}`}>
              <Download aria-hidden />
              Descargar Excel
            </a>
          </Button>
        )
      }
    >
      <Head title="Reportes" />

      <Panel className="mb-5">
        <div className="flex flex-wrap items-end gap-3 px-4 py-3">
          {organizationOptions.length > 0 && (
            <label className="flex flex-col gap-1 text-[11px] font-medium text-[var(--text-secondary)]">
              Organización
              <Select
                value={filters.organization ?? ''}
                onChange={(event) => apply({ organization: event.target.value || null })}
                className="h-8 w-60 text-[12.5px]"
              >
                <option value="">Todas</option>
                {organizationOptions.map((option) => (
                  <option key={option.value} value={option.value}>
                    {option.label}
                  </option>
                ))}
              </Select>
            </label>
          )}
          <label className="flex flex-col gap-1 text-[11px] font-medium text-[var(--text-secondary)]">
            Trabajos desde
            <Input
              type="date"
              value={filters.desde}
              onChange={(event) => event.target.value && apply({ desde: event.target.value })}
              className="h-8 text-[12.5px]"
            />
          </label>
          <label className="flex flex-col gap-1 text-[11px] font-medium text-[var(--text-secondary)]">
            hasta
            <Input
              type="date"
              value={filters.hasta}
              onChange={(event) => event.target.value && apply({ hasta: event.target.value })}
              className="h-8 text-[12.5px]"
            />
          </label>
        </div>
      </Panel>

      {/* ================= Status ================= */}
      <Panel className="mb-5">
        <PanelHeader title="Status de los equipos" description={`${formatNumber(report.status.total)} equipos en total.`} />
        <Tiles
          items={Object.entries(report.status.labels).map(([key, status]) => ({
            label: status.label,
            value: report.status.counts[key] ?? 0,
            tone: status.tone,
          }))}
        />
      </Panel>

      {/* ================= Vencimientos ================= */}
      <Panel className="mb-5">
        <PanelHeader
          title="Vencimientos"
          description="Equipos certificados que vencen pronto, ordenados por la fecha límite para mandarlos al taller."
        />
        <Tiles
          items={[
            ...Object.entries(report.expiry.windows).map(([days, value]) => ({
              label: `Vencen en ${days} días`,
              value,
              tone: 'warning' as Tone,
            })),
            { label: 'Fuera de tiempo para mandarlos', value: report.expiry.sendDeadlineMissed, tone: 'danger' as Tone },
          ]}
        />
        {report.expiry.items.length === 0 ? (
          <EmptyState icon={BarChart3} title="Sin vencimientos cercanos" description="Nada vence en los próximos 90 días." />
        ) : (
          <Table
            headers={['Equipo', ...(showOrganization ? ['Empresa'] : []), 'Estado', 'Vence', 'Mandar antes de']}
            rows={report.expiry.items.map((item) => [
              <div key="n">
                <Link href={`/activos/${item.id}`} className="font-medium text-[var(--text-accent)] hover:underline">
                  {item.name}
                </Link>
                <p className="font-mono text-[11px] text-[var(--text-muted)]">{item.serialNumber ?? '—'}</p>
              </div>,
              ...(showOrganization ? [item.organization ?? '—'] : []),
              <Badge key="s" tone={item.statusTone}>
                {item.statusLabel}
              </Badge>,
              <span key="v" className="tabular">
                {formatDate(item.expiresAt)} · {item.daysLeft} d
              </span>,
              <span
                key="m"
                className={cn('tabular', item.missed && 'flex items-center gap-1 text-[var(--tone-danger-fg)]')}
              >
                {item.missed && <AlertTriangle className="size-3.5" aria-hidden />}
                {formatDate(item.sendByAt)}
              </span>,
            ])}
          />
        )}
      </Panel>

      {/* ================= Trabajos ================= */}
      <Panel className="mb-5">
        <PanelHeader title="Trabajos en el taller" description="Ahora mismo, por etapa." />
        <Tiles
          items={[
            { label: 'Pendientes', value: report.work.now.pending },
            { label: 'En proceso', value: report.work.now.in_progress },
            { label: 'Rechazadas', value: report.work.now.rejected, tone: 'danger' as Tone },
            { label: 'Esperando al cliente', value: report.work.now.awaiting_client, tone: 'warning' as Tone },
            { label: 'Reparación', value: report.work.now.repair, tone: 'warning' as Tone },
            { label: 'Listas para despachar', value: report.work.now.ready },
          ]}
        />
      </Panel>

      <Panel className="mb-5">
        <PanelHeader
          title="En el período"
          description={`Del ${formatDate(filters.desde)} al ${formatDate(filters.hasta)}.`}
        />
        <Tiles
          items={[
            { label: 'Recibidos', value: report.work.period.received },
            { label: 'Despachados', value: report.work.period.dispatched },
            { label: 'Devueltos sin reparar', value: report.work.period.returnedUnrepaired, tone: 'danger' as Tone },
            { label: 'FMR emitidos', value: report.work.period.reportsIssued },
          ]}
        />
        {report.work.receptions.length > 0 && (
          <Table
            headers={['Equipo', ...(showOrganization ? ['Empresa'] : []), 'Ingreso', 'Etapa', 'Despachado']}
            rows={report.work.receptions.map((r) => [
              <div key="n">
                <Link href={r.url} className="font-medium text-[var(--text-accent)] hover:underline">
                  {r.asset ?? '—'}
                </Link>
                <p className="font-mono text-[11px] text-[var(--text-muted)]">{r.serialNumber ?? '—'}</p>
              </div>,
              ...(showOrganization ? [r.organization ?? '—'] : []),
              <span key="i" className="tabular">
                {formatDate(r.receivedAt)}
              </span>,
              <Badge key="e" tone={r.stageTone}>
                {r.stageLabel}
              </Badge>,
              <span key="d" className="tabular">
                {formatDate(r.dispatchedAt)}
              </span>,
            ])}
          />
        )}
      </Panel>

      <Panel>
        <PanelHeader title="FMR emitidos en el período" />
        {report.work.reports.length === 0 ? (
          <EmptyState icon={BarChart3} title="Sin FMR en el período" description="No se emitieron informes en esas fechas." />
        ) : (
          <Table
            headers={['Número', 'Fecha', 'Equipo', 'Piezas', ...(showOrganization ? ['Empresa'] : [])]}
            rows={report.work.reports.map((r) => [
              <Link key="n" href={`/informes/${r.id}`} className="font-mono text-[var(--text-accent)] hover:underline">
                {r.number}
              </Link>,
              <span key="f" className="tabular">
                {formatDate(r.inspectedAt)}
              </span>,
              r.equipment ?? '—',
              <span key="p" className="tabular">
                {r.assetsCount}
              </span>,
              ...(showOrganization ? [r.organization ?? '—'] : []),
            ])}
          />
        )}
      </Panel>
    </AppLayout>
  );
}

function Tiles({ items }: { items: { label: string; value: number; tone?: Tone }[] }) {
  return (
    <div className="grid gap-px border-y border-[var(--border-subtle)] bg-[var(--border-subtle)] sm:grid-cols-3 lg:grid-cols-6">
      {items.map((item) => (
        <div key={item.label} className="bg-[var(--surface-raised)] p-3.5">
          <p className="text-[11px] font-medium text-[var(--text-secondary)]">{item.label}</p>
          <p
            className={cn(
              'mt-1.5 text-[22px] leading-none font-semibold tabular',
              item.tone === 'danger' && item.value > 0 && 'text-[var(--tone-danger-fg)]',
              item.tone === 'warning' && item.value > 0 && 'text-[var(--tone-warning-fg)]',
            )}
          >
            {formatNumber(item.value)}
          </p>
        </div>
      ))}
    </div>
  );
}

function Table({ headers, rows }: { headers: string[]; rows: React.ReactNode[][] }) {
  return (
    <div className="overflow-x-auto">
      <table className="w-full min-w-[640px] text-[12.5px]">
        <thead>
          <tr>
            {headers.map((header) => (
              <th
                key={header}
                scope="col"
                className="h-9 border-b border-[var(--border-default)] bg-[var(--surface-sunken)] px-3 text-left text-[10px] font-semibold tracking-[0.08em] text-[var(--text-secondary)] uppercase"
              >
                {header}
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {rows.map((cells, i) => (
            <tr key={i} className="border-b border-[var(--border-subtle)] last:border-b-0 hover:bg-[var(--surface-hover)]">
              {cells.map((cell, j) => (
                <td key={j} className="px-3 py-2.5">
                  {cell}
                </td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
