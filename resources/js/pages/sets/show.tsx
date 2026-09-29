import { Head, Link } from '@inertiajs/react';
import { AlertTriangle, CheckCircle2, CircleDashed, Package, XCircle } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { EmptyState } from '@/components/ui/empty-state';
import { Panel, PanelHeader } from '@/components/ui/panel';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/cn';
import { expiryLabel, formatDate } from '@/lib/format';
import type { Tone } from '@/types';

interface SetDetail {
  id: string;
  name: string;
  notes: string | null;
  assetsCount: number;
  readiness: { fit: number; expiring: number; unfit: number; unknown: number };
}

interface SetAsset {
  id: string;
  position: number;
  name: string;
  serialNumber: string | null;
  code: string | null;
  partNumber: string | null;
  reportNumber: string | null;
  expiresAt: string | null;
  status: string;
  statusLabel: string;
  statusTone: Tone;
}

export default function SetShow({ set, assets }: { set: SetDetail; assets: SetAsset[] }) {
  const blocking = set.readiness.unfit + set.readiness.unknown;
  const ready = blocking === 0;

  return (
    <AppLayout
      title={set.name}
      description={set.notes ?? undefined}
      breadcrumbs={[{ label: 'Sets de equipo', href: '/sets' }, { label: set.name }]}
    >
      <Head title={set.name} />

      {/* ================= Disponibilidad del set =================
          Es la lectura que el sistema anterior no daba: saber si el conjunto
          completo puede salir a operar, no sólo el estado pieza por pieza. */}
      <Panel className="mb-5">
        <div
          className={cn(
            'flex flex-wrap items-center gap-3 border-b-2 px-4 py-3.5',
            ready ? 'border-[var(--tone-success-fg)]' : 'border-[var(--tone-danger-fg)]',
          )}
        >
          {ready ? (
            <CheckCircle2 className="size-5 text-[var(--tone-success-fg)]" aria-hidden />
          ) : (
            <XCircle className="size-5 text-[var(--tone-danger-fg)]" aria-hidden />
          )}
          <div>
            <p className="text-[14px] font-semibold">
              {ready ? 'Set disponible para operar' : 'Set no disponible'}
            </p>
            <p className="mt-0.5 text-[12px] text-[var(--text-secondary)]">
              {ready
                ? `Las ${set.assetsCount} piezas del set están vigentes.`
                : `${blocking} ${blocking === 1 ? 'pieza impide' : 'piezas impiden'} el despliegue del conjunto.`}
            </p>
          </div>
        </div>

        <div className="grid gap-px bg-[var(--border-subtle)] sm:grid-cols-4">
          {[
            { label: 'Aptas', value: set.readiness.fit, icon: CheckCircle2, tone: 'success' as Tone },
            { label: 'Próximas a vencer', value: set.readiness.expiring, icon: AlertTriangle, tone: 'warning' as Tone },
            { label: 'No aptas', value: set.readiness.unfit, icon: XCircle, tone: 'danger' as Tone },
            { label: 'Sin inspección', value: set.readiness.unknown, icon: CircleDashed, tone: 'neutral' as Tone },
          ].map((item) => (
            <div key={item.label} className="bg-[var(--surface-raised)] p-4">
              <div className="flex items-center justify-between gap-2">
                <p className="text-[11px] font-medium text-[var(--text-secondary)]">{item.label}</p>
                <item.icon
                  className={cn(
                    'size-4',
                    item.tone === 'success' && 'text-[var(--tone-success-fg)]',
                    item.tone === 'warning' && 'text-[var(--tone-warning-fg)]',
                    item.tone === 'danger' && 'text-[var(--tone-danger-fg)]',
                    item.tone === 'neutral' && 'text-[var(--text-muted)]',
                  )}
                  aria-hidden
                />
              </div>
              <p className="mt-1.5 text-[26px] leading-none font-semibold tabular">{item.value}</p>
            </div>
          ))}
        </div>
      </Panel>

      <Panel>
        <PanelHeader title="Composición del set" description={`${set.assetsCount} piezas.`} />

        {assets.length === 0 ? (
          <EmptyState icon={Package} title="Set vacío" description="Todavía no tiene piezas asignadas." />
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full min-w-[640px] text-[12.5px]">
              <thead>
                <tr>
                  {['#', 'Pieza', 'Serie', 'Informe', 'Vencimiento', 'Estado'].map((header) => (
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
                {assets.map((asset) => (
                  <tr
                    key={asset.id}
                    className="border-b border-[var(--border-subtle)] last:border-b-0 hover:bg-[var(--surface-hover)]"
                  >
                    <td className="px-3 py-2.5 font-mono text-[11px] text-[var(--text-muted)]">
                      {String(asset.position).padStart(2, '0')}
                    </td>
                    <td className="px-3 py-2.5">
                      <Link
                        href={`/activos/${asset.id}`}
                        className="font-medium text-[var(--text-accent)] hover:underline"
                      >
                        {asset.name}
                      </Link>
                      {asset.partNumber && (
                        <p className="mt-0.5 font-mono text-[10.5px] text-[var(--text-muted)]">
                          {asset.partNumber}
                        </p>
                      )}
                    </td>
                    <td className="px-3 py-2.5 font-mono text-[11.5px]">{asset.serialNumber ?? '—'}</td>
                    <td className="px-3 py-2.5 font-mono text-[11.5px] text-[var(--text-secondary)]">
                      {asset.reportNumber ?? '—'}
                    </td>
                    <td className="px-3 py-2.5">
                      <span className="tabular">{formatDate(asset.expiresAt)}</span>
                      <span className="mt-0.5 block text-[10.5px] text-[var(--text-muted)]">
                        {expiryLabel(asset.expiresAt)}
                      </span>
                    </td>
                    <td className="px-3 py-2.5">
                      <Badge tone={asset.statusTone}>{asset.statusLabel}</Badge>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Panel>
    </AppLayout>
  );
}
