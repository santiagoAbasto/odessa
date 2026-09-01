import { Link, router } from '@inertiajs/react';
import {
  flexRender,
  getCoreRowModel,
  useReactTable,
  type ColumnDef,
  type VisibilityState,
} from '@tanstack/react-table';
import { ArrowDown, ArrowUp, ArrowUpDown, Columns3, Rows3 } from 'lucide-react';
import { useEffect, useMemo, useState, type ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import {
  DropdownMenu,
  DropdownMenuCheckboxItem,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { TableSkeleton } from '@/components/ui/skeleton';
import { cn } from '@/lib/cn';
import { formatNumber } from '@/lib/format';
import type { Paginated, SortState } from '@/types';

export type Density = 'compact' | 'normal' | 'comfortable';

const densityLabels: Record<Density, string> = {
  compact: 'Compacta',
  normal: 'Normal',
  comfortable: 'Cómoda',
};

/** Metadatos por columna: alias de orden y alineación. */
export interface ColumnMeta {
  sortKey?: string;
  align?: 'left' | 'right';
  /** Columna que no debe poder ocultarse (la identidad de la fila). */
  locked?: boolean;
}

interface DataTableProps<T> {
  columns: ColumnDef<T, unknown>[];
  page: Paginated<T>;
  sort?: SortState;
  /** Ruta base para reconstruir la URL al ordenar. */
  baseUrl: string;
  /** Parámetros vigentes que deben preservarse al ordenar o paginar. */
  query: Record<string, string | number | undefined>;
  loading?: boolean;
  emptyState: ReactNode;
  /** Identificador para persistir densidad y columnas visibles. */
  storageKey: string;
  rowHref?: (row: T) => string;
  toolbar?: ReactNode;
}

export function DataTable<T extends { id: string | number }>({
  columns,
  page,
  sort,
  baseUrl,
  query,
  loading = false,
  emptyState,
  storageKey,
  rowHref,
  toolbar,
}: DataTableProps<T>) {
  const [density, setDensity] = useState<Density>('normal');
  const [columnVisibility, setColumnVisibility] = useState<VisibilityState>({});

  // Preferencias de vista: viven en el navegador porque son personales y no
  // deben viajar al servidor en cada petición.
  useEffect(() => {
    const savedDensity = localStorage.getItem(`odessa.${storageKey}.density`);
    if (savedDensity && savedDensity in densityLabels) setDensity(savedDensity as Density);

    const savedColumns = localStorage.getItem(`odessa.${storageKey}.columns`);
    if (savedColumns) {
      try {
        setColumnVisibility(JSON.parse(savedColumns) as VisibilityState);
      } catch {
        /* preferencia corrupta: se ignora y se usa el default */
      }
    }
  }, [storageKey]);

  const table = useReactTable({
    data: page.data,
    columns,
    getCoreRowModel: getCoreRowModel(),
    state: { columnVisibility },
    onColumnVisibilityChange: (updater) => {
      const next = typeof updater === 'function' ? updater(columnVisibility) : updater;
      setColumnVisibility(next);
      localStorage.setItem(`odessa.${storageKey}.columns`, JSON.stringify(next));
    },
    manualPagination: true,
    manualSorting: true,
  });

  const buildUrl = (params: Record<string, string | number | undefined>) => {
    const search = new URLSearchParams();
    Object.entries({ ...query, ...params }).forEach(([key, value]) => {
      if (value !== undefined && value !== '' && value !== null) search.set(key, String(value));
    });
    const qs = search.toString();
    return qs ? `${baseUrl}?${qs}` : baseUrl;
  };

  const applyDensity = (value: Density) => {
    setDensity(value);
    localStorage.setItem(`odessa.${storageKey}.density`, value);
  };

  const hideableColumns = useMemo(
    () => table.getAllLeafColumns().filter((column) => !(column.columnDef.meta as ColumnMeta)?.locked),
    [table, columnVisibility],
  );

  return (
    <div data-density={density} className="flex flex-col">
      {/* --- Barra de herramientas --- */}
      <div className="flex flex-wrap items-center justify-between gap-2 border-b border-[var(--border-subtle)] px-3 py-2.5">
        <div className="flex min-w-0 flex-1 flex-wrap items-center gap-2">{toolbar}</div>

        <div className="flex shrink-0 items-center gap-1.5">
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <Button variant="ghost" size="sm" aria-label="Densidad de filas">
                <Rows3 aria-hidden />
                <span className="hidden sm:inline">{densityLabels[density]}</span>
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent>
              <DropdownMenuLabel>Densidad</DropdownMenuLabel>
              {(Object.keys(densityLabels) as Density[]).map((option) => (
                <DropdownMenuItem key={option} onSelect={() => applyDensity(option)}>
                  <span className={cn(density === option && 'font-semibold text-[var(--text-accent)]')}>
                    {densityLabels[option]}
                  </span>
                </DropdownMenuItem>
              ))}
            </DropdownMenuContent>
          </DropdownMenu>

          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <Button variant="ghost" size="sm" aria-label="Columnas visibles">
                <Columns3 aria-hidden />
                <span className="hidden sm:inline">Columnas</span>
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent>
              <DropdownMenuLabel>Mostrar columnas</DropdownMenuLabel>
              {hideableColumns.map((column) => (
                <DropdownMenuCheckboxItem
                  key={column.id}
                  checked={column.getIsVisible()}
                  onCheckedChange={(value) => column.toggleVisibility(value)}
                >
                  {typeof column.columnDef.header === 'string' ? column.columnDef.header : column.id}
                </DropdownMenuCheckboxItem>
              ))}
            </DropdownMenuContent>
          </DropdownMenu>
        </div>
      </div>

      {/* --- Tabla --- */}
      {loading ? (
        <TableSkeleton rows={8} columns={Math.min(columns.length, 6)} />
      ) : page.data.length === 0 ? (
        emptyState
      ) : (
        <div className="overflow-x-auto">
          <table className="w-full min-w-[720px] border-collapse text-[12.5px]">
            <thead className="sticky top-0 z-10">
              {table.getHeaderGroups().map((headerGroup) => (
                <tr key={headerGroup.id}>
                  {headerGroup.headers.map((header) => {
                    const meta = header.column.columnDef.meta as ColumnMeta | undefined;
                    const sortKey = meta?.sortKey;
                    const isSorted = sortKey && sort?.column === sortKey;
                    const nextDirection = isSorted && sort?.direction === 'asc' ? 'desc' : 'asc';
                    const SortIcon = isSorted
                      ? sort?.direction === 'asc'
                        ? ArrowUp
                        : ArrowDown
                      : ArrowUpDown;

                    return (
                      <th
                        key={header.id}
                        scope="col"
                        aria-sort={
                          isSorted ? (sort?.direction === 'asc' ? 'ascending' : 'descending') : 'none'
                        }
                        className={cn(
                          'h-9 border-b border-[var(--border-default)] bg-[var(--surface-sunken)] px-3',
                          'text-[10px] font-semibold tracking-[0.08em] text-[var(--text-secondary)] uppercase',
                          meta?.align === 'right' ? 'text-right' : 'text-left',
                        )}
                      >
                        {sortKey ? (
                          <Link
                            href={buildUrl({ sort: sortKey, direction: nextDirection, page: undefined })}
                            preserveScroll
                            preserveState
                            className={cn(
                              'inline-flex items-center gap-1 hover:text-[var(--text-primary)]',
                              isSorted && 'text-[var(--text-accent)]',
                            )}
                          >
                            {flexRender(header.column.columnDef.header, header.getContext())}
                            <SortIcon className="size-3" aria-hidden />
                          </Link>
                        ) : (
                          flexRender(header.column.columnDef.header, header.getContext())
                        )}
                      </th>
                    );
                  })}
                </tr>
              ))}
            </thead>

            <tbody>
              {table.getRowModel().rows.map((row) => {
                const href = rowHref?.(row.original);

                return (
                  <tr
                    key={row.id}
                    onClick={href ? () => router.visit(href) : undefined}
                    className={cn(
                      'border-b border-[var(--border-subtle)] last:border-b-0',
                      href && 'cursor-pointer hover:bg-[var(--surface-hover)]',
                    )}
                  >
                    {row.getVisibleCells().map((cell) => {
                      const meta = cell.column.columnDef.meta as ColumnMeta | undefined;

                      return (
                        <td
                          key={cell.id}
                          className={cn(
                            'h-[var(--row-height)] px-3 align-middle',
                            meta?.align === 'right' && 'text-right',
                          )}
                        >
                          {flexRender(cell.column.columnDef.cell, cell.getContext())}
                        </td>
                      );
                    })}
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      )}

      {page.data.length > 0 && <Pagination page={page} buildUrl={buildUrl} />}
    </div>
  );
}

function Pagination<T>({
  page,
  buildUrl,
}: {
  page: Paginated<T>;
  buildUrl: (params: Record<string, string | number | undefined>) => string;
}) {
  return (
    <nav
      aria-label="Paginación"
      className="flex flex-wrap items-center justify-between gap-3 border-t border-[var(--border-subtle)] px-3 py-2.5"
    >
      <p className="text-[11.5px] text-[var(--text-secondary)]">
        Mostrando{' '}
        <span className="font-medium text-[var(--text-primary)]">
          {formatNumber(page.from ?? 0)}–{formatNumber(page.to ?? 0)}
        </span>{' '}
        de <span className="font-medium text-[var(--text-primary)]">{formatNumber(page.total)}</span>{' '}
        registros
      </p>

      <div className="flex items-center gap-1.5">
        <label className="flex items-center gap-1.5 text-[11.5px] text-[var(--text-secondary)]">
          <span className="hidden sm:inline">Por página</span>
          <select
            value={page.per_page}
            onChange={(event) =>
              router.visit(buildUrl({ per_page: event.target.value, page: undefined }), {
                preserveState: true,
                preserveScroll: true,
              })
            }
            className="h-7 rounded-xs border border-[var(--border-default)] bg-[var(--surface-raised)] px-1.5 text-[11.5px]"
          >
            {[25, 50, 100].map((size) => (
              <option key={size} value={size}>
                {size}
              </option>
            ))}
          </select>
        </label>

        <Button
          asChild={page.current_page > 1}
          variant="secondary"
          size="sm"
          disabled={page.current_page <= 1}
        >
          {page.current_page > 1 ? (
            <Link href={buildUrl({ page: page.current_page - 1 })} preserveScroll>
              Anterior
            </Link>
          ) : (
            <span>Anterior</span>
          )}
        </Button>

        <span className="px-1 text-[11.5px] text-[var(--text-secondary)]">
          {page.current_page} / {page.last_page}
        </span>

        <Button
          asChild={page.current_page < page.last_page}
          variant="secondary"
          size="sm"
          disabled={page.current_page >= page.last_page}
        >
          {page.current_page < page.last_page ? (
            <Link href={buildUrl({ page: page.current_page + 1 })} preserveScroll>
              Siguiente
            </Link>
          ) : (
            <span>Siguiente</span>
          )}
        </Button>
      </div>
    </nav>
  );
}
