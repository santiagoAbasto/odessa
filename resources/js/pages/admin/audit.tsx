import { Head, Link, router } from '@inertiajs/react';
import { ScrollText } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { EmptyState } from '@/components/ui/empty-state';
import { Input, Select } from '@/components/ui/input';
import { Panel } from '@/components/ui/panel';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime } from '@/lib/format';
import type { Paginated, Tone } from '@/types';

interface LogRow {
  id: number;
  /** Código interno. Se conserva para el filtro, no se muestra. */
  action: string;
  actionLabel: string;
  actionArea: string;
  actionTone: Tone;
  description: string | null;
  user: string | null;
  userEmail: string | null;
  organization: string | null;
  subjectType: string | null;
  asset: { id: string; name: string; serial: string | null } | null;
  ipAddress: string | null;
  createdAt: string | null;
  changes: { old?: Record<string, unknown>; new?: Record<string, unknown> } | null;
}

interface ActionOption {
  value: string;
  label: string;
  area: string;
}

export default function Audit({
  logs,
  filters,
  actionOptions,
  organizationOptions,
}: {
  logs: Paginated<LogRow>;
  filters: Record<string, string | undefined>;
  actionOptions: ActionOption[];
  organizationOptions: { value: string; label: string }[];
}) {
  const update = (patch: Record<string, string | undefined>) =>
    router.get(
      '/administracion/auditoria',
      Object.fromEntries(Object.entries({ ...filters, ...patch }).filter(([, value]) => value)),
      { preserveState: true, preserveScroll: true, replace: true },
    );

  return (
    <AppLayout
      title="Auditoría"
      description="Registro inmutable de accesos y acciones sensibles. No se puede editar ni eliminar desde la aplicación."
      breadcrumbs={[{ label: 'Administración' }, { label: 'Auditoría' }]}
    >
      <Head title="Auditoría" />

      <Panel>
        <div className="flex flex-wrap items-center gap-2 border-b border-[var(--border-subtle)] px-3 py-2.5">
          <Input
            type="search"
            defaultValue={filters.search ?? ''}
            onChange={(event) => update({ search: event.target.value || undefined })}
            placeholder="Descripción o correo…"
            aria-label="Buscar en la auditoría"
            className="h-8 max-w-xs text-[12.5px]"
          />

          <Select
            value={filters.action ?? ''}
            onChange={(event) => update({ action: event.target.value || undefined })}
            aria-label="Filtrar por acción"
            className="h-8 w-auto text-[12.5px]"
          >
            <option value="">Todas las acciones</option>
            {/* Agrupadas por área: "Acceso", "Piezas", "Taller"… así el
                desplegable se recorre por tema y no por orden alfabético
                del código interno, que no significaba nada para quien audita. */}
            {Array.from(new Set(actionOptions.map((option) => option.area))).map((area) => (
              <optgroup key={area} label={area}>
                {actionOptions
                  .filter((option) => option.area === area)
                  .map((option) => (
                    <option key={option.value} value={option.value}>
                      {option.label}
                    </option>
                  ))}
              </optgroup>
            ))}
          </Select>

          {organizationOptions.length > 0 && (
            <Select
              value={filters.organization ?? ''}
              onChange={(event) => update({ organization: event.target.value || undefined })}
              aria-label="Filtrar por cliente"
              className="h-8 w-auto text-[12.5px]"
            >
              <option value="">Todos los clientes</option>
              {organizationOptions.map((option) => (
                <option key={option.value} value={option.value}>
                  {option.label}
                </option>
              ))}
            </Select>
          )}

          <label className="flex items-center gap-1.5 text-[11.5px] text-[var(--text-secondary)]">
            Desde
            <Input
              type="date"
              value={filters.date_from ?? ''}
              onChange={(event) => update({ date_from: event.target.value || undefined })}
              className="h-8 w-auto text-[12.5px]"
            />
          </label>

          <label className="flex items-center gap-1.5 text-[11.5px] text-[var(--text-secondary)]">
            Hasta
            <Input
              type="date"
              value={filters.date_to ?? ''}
              onChange={(event) => update({ date_to: event.target.value || undefined })}
              className="h-8 w-auto text-[12.5px]"
            />
          </label>
        </div>

        {logs.data.length === 0 ? (
          <EmptyState
            icon={ScrollText}
            title="Sin registros"
            description="No hay eventos que coincidan con los filtros."
          />
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full min-w-[820px] text-[12.5px]">
              <thead>
                <tr>
                  {['Fecha', 'Acción', 'Equipo', 'Detalle', 'Usuario', 'Cliente', 'IP'].map((header) => (
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
                {logs.data.map((log) => (
                  <tr key={log.id} className="border-b border-[var(--border-subtle)] last:border-b-0">
                    <td className="px-3 py-2.5 whitespace-nowrap tabular">
                      {formatDateTime(log.createdAt)}
                    </td>
                    <td className="px-3 py-2.5">
                      <Badge tone={log.actionTone}>{log.actionLabel}</Badge>
                      <p className="mt-1 text-[10.5px] text-[var(--text-muted)]">{log.actionArea}</p>
                    </td>
                    <td className="px-3 py-2.5">
                      {log.asset ? (
                        <Link
                          href={`/activos/${log.asset.id}`}
                          className="text-[var(--text-accent)] hover:underline"
                        >
                          <span className="block max-w-[200px] truncate">{log.asset.name}</span>
                          {log.asset.serial && (
                            <span className="mt-0.5 block font-mono text-[10.5px] text-[var(--text-muted)]">
                              {log.asset.serial}
                            </span>
                          )}
                        </Link>
                      ) : (
                        <span className="text-[var(--text-muted)]">—</span>
                      )}
                    </td>
                    <td className="px-3 py-2.5">
                      <p>{log.description ?? '—'}</p>
                      {log.changes && (
                        <details className="mt-1">
                          <summary className="cursor-pointer text-[11px] text-[var(--text-accent)]">
                            Ver cambios
                          </summary>
                          <pre className="mt-1.5 max-w-md overflow-x-auto rounded-xs bg-[var(--surface-sunken)] p-2 text-[10.5px]">
                            {JSON.stringify(log.changes, null, 2)}
                          </pre>
                        </details>
                      )}
                    </td>
                    <td className="px-3 py-2.5">
                      <p>{log.user ?? 'Sistema'}</p>
                      {log.userEmail && (
                        <p className="mt-0.5 text-[10.5px] text-[var(--text-muted)]">{log.userEmail}</p>
                      )}
                    </td>
                    <td className="px-3 py-2.5 text-[var(--text-secondary)]">{log.organization ?? '—'}</td>
                    <td className="px-3 py-2.5 font-mono text-[11px] text-[var(--text-muted)]">
                      {log.ipAddress ?? '—'}
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
