import { Head, Link } from '@inertiajs/react';
import { Layers } from 'lucide-react';
import { useMemo } from 'react';
import { FilterBar, type FilterDefinition } from '@/components/tables/filter-bar';
import { EmptyState } from '@/components/ui/empty-state';
import { Panel } from '@/components/ui/panel';
import AppLayout from '@/layouts/app-layout';
import { formatNumber } from '@/lib/format';
import type { Paginated, SelectOption } from '@/types';

interface SetRow {
  id: string;
  name: string;
  /** Empresa dueña del set. Null para un cliente: todo lo que ve es suyo. */
  organization: string | null;
  assetsCount: number;
  notes: string | null;
}

export default function SetsIndex({
  sets,
  filters,
  showOrganization,
  organizationOptions,
  statusOptions,
}: {
  sets: Paginated<SetRow>;
  filters: Record<string, string | undefined>;
  showOrganization: boolean;
  organizationOptions: SelectOption[];
  statusOptions: SelectOption[];
}) {
  /*
    Los mismos filtros que Activos, porque la pregunta que se hace acá es la
    misma: aparece un número de serie suelto y hay que saber a qué conjunto
    pertenece. Estado, serie y código filtran por las PIEZAS del set.
  */
  const filterDefinitions = useMemo<FilterDefinition[]>(
    () => [
      { key: 'search', label: 'Set', type: 'text' },
      ...(organizationOptions.length > 0
        ? [{ key: 'organization', label: 'Cliente', type: 'select' as const, options: organizationOptions }]
        : []),
      { key: 'status', label: 'Estado de sus piezas', type: 'select', options: statusOptions },
      { key: 'serial', label: 'Número de serie', type: 'text' },
      { key: 'code', label: 'Código', type: 'text' },
    ],
    [organizationOptions, statusOptions],
  );

  return (
    <AppLayout
      title="Sets de equipo"
      description="Agrupaciones de piezas que se despliegan como un conjunto. Un set está listo sólo si todas sus piezas están aptas."
      breadcrumbs={[{ label: 'Sets de equipo' }]}
    >
      <Head title="Sets de equipo" />

      <FilterBar
        baseUrl="/sets"
        filters={filters}
        definitions={filterDefinitions}
        searchPlaceholder="Buscar set por nombre o nota…"
      />

      {sets.data.length === 0 ? (
        <Panel>
          <EmptyState
            icon={Layers}
            title="Sin sets"
            description="No hay agrupaciones que coincidan con la búsqueda."
          />
        </Panel>
      ) : (
        <div className="grid gap-px overflow-hidden rounded-sm border border-[var(--border-subtle)] bg-[var(--border-subtle)] sm:grid-cols-2 lg:grid-cols-3">
          {sets.data.map((set) => (
            <Link
              key={set.id}
              href={`/sets/${set.id}`}
              className="group flex flex-col bg-[var(--surface-raised)] p-4 transition-colors hover:bg-[var(--surface-hover)]"
            >
              <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                  <h2 className="text-[14px] font-semibold tracking-tight">{set.name}</h2>
                  {/* De qué cliente es el set. Sin esto, desde Odessa las
                      tarjetas de todas las empresas se ven iguales. */}
                  {showOrganization && (
                    <p className="mt-0.5 truncate text-[11.5px] text-[var(--text-secondary)]">
                      {set.organization ?? '—'}
                    </p>
                  )}
                </div>
                <Layers className="size-4 shrink-0 text-[var(--text-muted)]" aria-hidden />
              </div>

              {set.notes && (
                <p className="mt-1.5 line-clamp-2 text-[12px] text-[var(--text-secondary)]">
                  {set.notes}
                </p>
              )}

              <p className="mt-auto pt-4 text-[11.5px] text-[var(--text-muted)]">
                <span className="text-[18px] font-semibold text-[var(--text-primary)] tabular">
                  {formatNumber(set.assetsCount)}
                </span>{' '}
                {set.assetsCount === 1 ? 'pieza' : 'piezas'}
              </p>
            </Link>
          ))}
        </div>
      )}
    </AppLayout>
  );
}
