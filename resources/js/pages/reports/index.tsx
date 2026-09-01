import { Head, Link } from '@inertiajs/react';
import type { ColumnDef } from '@tanstack/react-table';
import { FileSearch } from 'lucide-react';
import { useMemo } from 'react';
import { DataTable, type ColumnMeta } from '@/components/tables/data-table';
import { FilterBar, type FilterDefinition } from '@/components/tables/filter-bar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { Panel } from '@/components/ui/panel';
import AppLayout from '@/layouts/app-layout';
import { formatDate } from '@/lib/format';
import type { Paginated, ReportRow, SelectOption, SortState } from '@/types';

interface ReportsIndexProps {
  reports: Paginated<ReportRow>;
  filters: Record<string, string | undefined>;
  sort: SortState;
  statusOptions: SelectOption[];
  /** Falso para un cliente: todo lo que ve ya es suyo. */
  showOrganization: boolean;
}

export default function ReportsIndex({
  reports,
  filters,
  sort,
  statusOptions,
  showOrganization,
}: ReportsIndexProps) {
  const filterDefinitions = useMemo<FilterDefinition[]>(
    () => [
      { key: 'search', label: 'Número', type: 'text' },
      { key: 'status', label: 'Estado', type: 'select', options: statusOptions },
      { key: 'equipment', label: 'Equipo o set', type: 'text' },
      { key: 'date_from', label: 'Inspección desde', type: 'date' },
      { key: 'date_to', label: 'Inspección hasta', type: 'date' },
    ],
    [statusOptions],
  );

  const columns = useMemo<ColumnDef<ReportRow, unknown>[]>(
    () => [
      {
        id: 'number',
        header: 'Nº FMR',
        meta: { sortKey: 'number', locked: true } satisfies ColumnMeta,
        cell: ({ row }) => (
          <span className="font-mono text-[12.5px] font-semibold">{row.original.number}</span>
        ),
      },
      // De qué cliente es el informe. Un FMR sin dueño visible obliga a
      // abrirlo para saber a quién pertenece.
      ...(showOrganization
        ? ([
            {
              id: 'organization',
              header: 'Cliente',
              cell: ({ row }) => (
                <span className="block max-w-[170px] truncate text-[var(--text-secondary)]">
                  {row.original.organization ?? '—'}
                </span>
              ),
            },
          ] as ColumnDef<ReportRow, unknown>[])
        : []),
      {
        id: 'equipment',
        header: 'Equipo',
        meta: { sortKey: 'equipment' } satisfies ColumnMeta,
        cell: ({ row }) => (
          <span className="text-[12.5px]">{row.original.equipment ?? '—'}</span>
        ),
      },
      {
        id: 'inspectedAt',
        header: 'Fecha',
        meta: { sortKey: 'inspected_at' } satisfies ColumnMeta,
        cell: ({ row }) => <span className="tabular">{formatDate(row.original.inspectedAt)}</span>,
      },
      {
        id: 'assetsCount',
        header: 'Piezas',
        meta: { align: 'right' } satisfies ColumnMeta,
        cell: ({ row }) => <span className="tabular">{row.original.assetsCount}</span>,
      },
      {
        id: 'filesCount',
        header: 'Documentos',
        meta: { align: 'right' } satisfies ColumnMeta,
        cell: ({ row }) => <span className="tabular">{row.original.filesCount}</span>,
      },
      {
        id: 'status',
        header: 'Estado',
        meta: { sortKey: 'status' } satisfies ColumnMeta,
        cell: ({ row }) => <Badge tone={row.original.statusTone}>{row.original.statusLabel}</Badge>,
      },
    ],
    [showOrganization],
  );

  return (
    <AppLayout
      title="Informes FMR"
      description="Registro de inspecciones realizadas, con sus piezas y documentación asociada."
      breadcrumbs={[{ label: 'Informes FMR' }]}
    >
      <Head title="Informes FMR" />

      <Panel>
        <DataTable
          columns={columns}
          page={reports}
          sort={sort}
          baseUrl="/informes"
          query={filters}
          storageKey="reports"
          rowHref={(row) => `/informes/${row.id}`}
          toolbar={
            <FilterBar
              baseUrl="/informes"
              filters={filters}
              definitions={filterDefinitions}
              searchPlaceholder="Número de FMR…"
            />
          }
          emptyState={
            <EmptyState
              icon={FileSearch}
              title="Sin informes"
              description="No hay FMR que coincidan con los filtros aplicados."
              action={
                <Button asChild variant="secondary" size="sm">
                  <Link href="/informes">Quitar filtros</Link>
                </Button>
              }
            />
          }
        />
      </Panel>
    </AppLayout>
  );
}
