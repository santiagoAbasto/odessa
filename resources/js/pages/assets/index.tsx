import { Head, Link } from '@inertiajs/react';
import type { ColumnDef } from '@tanstack/react-table';
import { Boxes, PackageSearch, Sheet, Wrench } from 'lucide-react';
import { useMemo } from 'react';
import { DataTable, type ColumnMeta } from '@/components/tables/data-table';
import { FilterBar, type FilterDefinition } from '@/components/tables/filter-bar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { Panel } from '@/components/ui/panel';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/cn';
import { expiryLabel, formatDate } from '@/lib/format';
import type { AssetRow, Paginated, SelectOption, SortState } from '@/types';

interface AssetsIndexProps {
  kind: 'part' | 'generic';
  assets: Paginated<AssetRow>;
  filters: Record<string, string | undefined>;
  sort: SortState;
  statusOptions: SelectOption[];
  /** Falso para un cliente: todo lo que ve ya es suyo. */
  showOrganization: boolean;
  organizationOptions: SelectOption[];
}

export default function AssetsIndex({
  kind,
  assets,
  filters,
  sort,
  statusOptions,
  showOrganization,
  organizationOptions,
}: AssetsIndexProps) {
  const isGeneric = kind === 'generic';

  const filterDefinitions = useMemo<FilterDefinition[]>(
    () => [
      { key: 'search', label: 'Búsqueda', type: 'text' },
      { key: 'status', label: 'Estado', type: 'select', options: statusOptions },
      ...(showOrganization
        ? ([
            { key: 'organization', label: 'Cliente', type: 'select', options: organizationOptions },
          ] as FilterDefinition[])
        : []),
      { key: 'serial', label: 'Número de serie', type: 'text' },
      { key: 'code', label: 'Código', type: 'text' },
      { key: 'client_reference', label: 'Referencia del cliente', type: 'text' },
      { key: 'report_number', label: 'Número de FMR', type: 'text' },
      { key: 'expires_from', label: 'Vence desde', type: 'date' },
      { key: 'expires_to', label: 'Vence hasta', type: 'date' },
      ...(isGeneric
        ? ([
            { key: 'brand', label: 'Marca', type: 'text' },
            { key: 'model', label: 'Modelo', type: 'text' },
          ] as FilterDefinition[])
        : []),
    ],
    [statusOptions, isGeneric, showOrganization, organizationOptions],
  );

  const columns = useMemo<ColumnDef<AssetRow, unknown>[]>(() => {
    const base: ColumnDef<AssetRow, unknown>[] = [
      {
        id: 'name',
        header: isGeneric ? 'Genérico' : 'Parte',
        meta: { sortKey: 'description', locked: true } satisfies ColumnMeta,
        cell: ({ row }) => (
          <div className="min-w-0">
            <p className="truncate font-medium">{row.original.name}</p>
            <p className="mt-0.5 truncate font-mono text-[11px] text-[var(--text-muted)]">
              {isGeneric
                ? [row.original.brand, row.original.model].filter(Boolean).join(' · ') || '—'
                : (row.original.partNumber ?? '—')}
            </p>
          </div>
        ),
      },
      /*
        De quién es el equipo. Va pegada al nombre porque, desde Odessa, saber
        a qué cliente pertenece la pieza es tan identificatorio como su
        descripción. A un cliente no se le muestra: todo lo que ve es suyo.
      */
      ...(showOrganization
        ? ([
            {
              id: 'organization',
              header: 'Cliente',
              cell: ({ row }) => (
                <span className="block max-w-[180px] truncate text-[var(--text-secondary)]">
                  {row.original.organization ?? '—'}
                </span>
              ),
            },
          ] as ColumnDef<AssetRow, unknown>[])
        : []),
      {
        id: 'serialNumber',
        header: isGeneric ? 'ID / Serie' : 'Nº de serie',
        meta: { sortKey: 'serial' } satisfies ColumnMeta,
        cell: ({ row }) => (
          <span className="font-mono text-[11.5px]">
            {row.original.serialNumber ?? row.original.identifier ?? '—'}
          </span>
        ),
      },
      {
        id: 'code',
        header: 'Código',
        meta: { sortKey: 'code' } satisfies ColumnMeta,
        cell: ({ row }) => (
          <span className="font-mono text-[11.5px] text-[var(--text-secondary)]">
            {row.original.code ?? '—'}
          </span>
        ),
      },
      {
        id: 'clientReference',
        header: 'Ref. cliente',
        meta: { sortKey: 'client_reference' } satisfies ColumnMeta,
        cell: ({ row }) => (
          <span className="font-mono text-[11.5px] text-[var(--text-secondary)]">
            {row.original.clientReference ?? '—'}
          </span>
        ),
      },
      {
        id: 'report',
        header: 'FMR',
        cell: ({ row }) =>
          row.original.reportId ? (
            <Link
              href={`/informes/${row.original.reportId}`}
              onClick={(event) => event.stopPropagation()}
              className="font-mono text-[11.5px] text-[var(--text-accent)] hover:underline"
            >
              {row.original.reportNumber}
            </Link>
          ) : (
            <span className="text-[var(--text-muted)]">—</span>
          ),
      },
      {
        id: 'expiresAt',
        header: 'Vencimiento',
        meta: { sortKey: 'expires_at' } satisfies ColumnMeta,
        cell: ({ row }) => (
          <div>
            <p className="tabular">{formatDate(row.original.expiresAt)}</p>
            <p
              className={cn(
                'mt-0.5 text-[10.5px]',
                row.original.status === 'unfit'
                  ? 'text-[var(--tone-danger-fg)]'
                  : row.original.status === 'expiring'
                    ? 'text-[var(--tone-warning-fg)]'
                    : 'text-[var(--text-muted)]',
              )}
            >
              {expiryLabel(row.original.expiresAt)}
            </p>
          </div>
        ),
      },
      {
        id: 'status',
        header: 'Estado',
        meta: { sortKey: 'status' } satisfies ColumnMeta,
        cell: ({ row }) => <Badge tone={row.original.statusTone}>{row.original.statusLabel}</Badge>,
      },
    ];

    return base;
  }, [isGeneric, showOrganization]);

  return (
    <AppLayout
      title="Activos"
      description="Inventario de piezas y equipamiento genérico con su estado de aptitud vigente."
      breadcrumbs={[{ label: 'Activos' }]}
      actions={
        /*
          La descarga arrastra los filtros activos: se baja exactamente lo que
          se está viendo. Es un enlace y no un botón con fetch porque el
          navegador tiene que encargarse de guardar el archivo.
        */
        <Button asChild variant="secondary" size="sm">
          <a
            href={`/activos/exportar?${new URLSearchParams(
              Object.entries({ ...filters, kind }).filter(([, value]) => value) as [string, string][],
            ).toString()}`}
          >
            <Sheet aria-hidden />
            Exportar a Excel
          </a>
        </Button>
      }
    >
      <Head title="Activos" />

      {/* Conmutador de tipo: reemplaza los dos menús separados del sistema anterior. */}
      <div
        role="tablist"
        aria-label="Tipo de activo"
        className="mb-4 inline-flex rounded-sm border border-[var(--border-default)] bg-[var(--surface-raised)] p-0.5"
      >
        {[
          { value: 'part', label: 'Partes', icon: Wrench },
          { value: 'generic', label: 'Genéricos', icon: Boxes },
        ].map((tab) => (
          <Link
            key={tab.value}
            href="/activos"
            data={{ kind: tab.value }}
            preserveScroll
            role="tab"
            aria-selected={kind === tab.value}
            className={cn(
              'inline-flex items-center gap-1.5 rounded-xs px-3 py-1.5 text-[12.5px] transition-colors',
              kind === tab.value
                ? 'bg-[var(--accent)] font-medium text-[var(--text-inverse)]'
                : 'text-[var(--text-secondary)] hover:bg-[var(--surface-hover)]',
            )}
          >
            <tab.icon className="size-3.5" aria-hidden />
            {tab.label}
          </Link>
        ))}
      </div>

      <Panel>
        <DataTable
          columns={columns}
          page={assets}
          sort={sort}
          baseUrl="/activos"
          query={{ ...filters, kind }}
          storageKey={`assets.${kind}`}
          rowHref={(row) => `/activos/${row.id}`}
          toolbar={
            <FilterBar
              baseUrl="/activos"
              filters={{ ...filters, kind }}
              definitions={filterDefinitions}
              searchPlaceholder="Descripción, serie, código o referencia…"
            />
          }
          emptyState={
            <EmptyState
              icon={PackageSearch}
              title="Sin resultados"
              description="No hay activos que coincidan con los filtros aplicados."
              action={
                <Button asChild variant="secondary" size="sm">
                  <Link href={`/activos?kind=${kind}`}>Quitar filtros</Link>
                </Button>
              }
            />
          }
        />
      </Panel>
    </AppLayout>
  );
}
