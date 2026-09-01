import { Head, Link } from '@inertiajs/react';
import { Layers, Search } from 'lucide-react';
import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { EmptyState } from '@/components/ui/empty-state';
import { Panel } from '@/components/ui/panel';
import AppLayout from '@/layouts/app-layout';
import { formatNumber } from '@/lib/format';
import type { Paginated } from '@/types';

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
}: {
  sets: Paginated<SetRow>;
  filters: Record<string, string | undefined>;
  showOrganization: boolean;
}) {
  const [search, setSearch] = useState(filters.search ?? '');
  const [mounted, setMounted] = useState(false);

  useEffect(() => {
    if (!mounted) {
      setMounted(true);
      return;
    }
    const timer = setTimeout(() => {
      router.get('/sets', search ? { search } : {}, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
      });
    }, 350);
    return () => clearTimeout(timer);
  }, [search]);

  return (
    <AppLayout
      title="Sets de equipo"
      description="Agrupaciones de piezas que se despliegan como un conjunto. Un set está listo sólo si todas sus piezas están aptas."
      breadcrumbs={[{ label: 'Sets de equipo' }]}
    >
      <Head title="Sets de equipo" />

      <div className="mb-4 relative max-w-xs">
        <label htmlFor="buscar-set" className="sr-only">
          Buscar set por nombre
        </label>
        <Search
          className="pointer-events-none absolute top-1/2 left-2.5 size-3.5 -translate-y-1/2 text-[var(--text-muted)]"
          aria-hidden
        />
        <input
          id="buscar-set"
          type="search"
          value={search}
          onChange={(event) => setSearch(event.target.value)}
          placeholder="Buscar set…"
          className="h-8 w-full rounded-xs border border-[var(--border-default)] bg-[var(--surface-raised)] pr-2.5 pl-8 text-[12.5px] outline-none focus:border-[var(--focus-ring)]"
        />
      </div>

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
