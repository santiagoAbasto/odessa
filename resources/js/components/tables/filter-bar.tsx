import { router } from '@inertiajs/react';
import { Search, SlidersHorizontal, X } from 'lucide-react';
import { useEffect, useRef, useState, type ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import { Input, Select } from '@/components/ui/input';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import type { SelectOption } from '@/types';

export interface FilterDefinition {
  key: string;
  label: string;
  type: 'text' | 'select' | 'date';
  options?: SelectOption[];
  placeholder?: string;
}

interface FilterBarProps {
  baseUrl: string;
  filters: Record<string, string | undefined>;
  definitions: FilterDefinition[];
  searchPlaceholder?: string;
  extra?: ReactNode;
}

/**
 * Búsqueda y filtros avanzados de un listado.
 *
 * La búsqueda libre va con debounce para no lanzar una petición por tecla;
 * los filtros avanzados se aplican en bloque desde el panel, que es lo que
 * espera alguien que está afinando una consulta larga.
 */
export function FilterBar({
  baseUrl,
  filters,
  definitions,
  searchPlaceholder = 'Buscar…',
  extra,
}: FilterBarProps) {
  const [search, setSearch] = useState(filters.search ?? '');
  const [draft, setDraft] = useState<Record<string, string>>(() => buildDraft(filters, definitions));
  const [open, setOpen] = useState(false);
  const firstRender = useRef(true);

  // Debounce de 350 ms: la petición anterior se cancela sola porque Inertia
  // aborta la visita en curso al lanzar una nueva sobre la misma URL.
  useEffect(() => {
    if (firstRender.current) {
      firstRender.current = false;
      return;
    }

    const timer = setTimeout(() => {
      apply({ ...activeFilters(filters, definitions), search: search || undefined });
    }, 350);

    return () => clearTimeout(timer);
  }, [search]);

  const apply = (params: Record<string, string | undefined>) => {
    const clean = Object.fromEntries(
      Object.entries(params).filter(([, value]) => value !== undefined && value !== ''),
    );
    router.get(baseUrl, clean, { preserveState: true, preserveScroll: true, replace: true });
  };

  const advanced = definitions.filter((definition) => definition.key !== 'search');
  const activeCount = advanced.filter((definition) => filters[definition.key]).length;

  const clearAll = () => {
    setSearch('');
    setDraft({});
    router.get(baseUrl, {}, { preserveState: true, preserveScroll: true, replace: true });
  };

  return (
    <>
      <div className="relative min-w-0 flex-1 sm:max-w-xs">
        <label htmlFor="filtro-busqueda" className="sr-only">
          {searchPlaceholder}
        </label>
        <Search
          className="pointer-events-none absolute top-1/2 left-2.5 size-3.5 -translate-y-1/2 text-[var(--text-muted)]"
          aria-hidden
        />
        <input
          id="filtro-busqueda"
          type="search"
          value={search}
          onChange={(event) => setSearch(event.target.value)}
          placeholder={searchPlaceholder}
          className="h-8 w-full rounded-xs border border-[var(--border-default)] bg-[var(--surface-raised)] pr-2.5 pl-8 text-[12.5px] outline-none focus:border-[var(--focus-ring)]"
        />
      </div>

      {advanced.length > 0 && (
        <Popover open={open} onOpenChange={setOpen}>
          <PopoverTrigger asChild>
            <Button variant="secondary" size="sm">
              <SlidersHorizontal aria-hidden />
              Filtros
              {activeCount > 0 && (
                <span className="ml-0.5 grid size-4 place-items-center rounded-full bg-[var(--accent)] text-[9.5px] font-semibold text-[var(--text-inverse)]">
                  {activeCount}
                </span>
              )}
            </Button>
          </PopoverTrigger>

          <PopoverContent className="w-80">
            <form
              onSubmit={(event) => {
                event.preventDefault();
                apply({ ...draft, search: search || undefined });
                setOpen(false);
              }}
              className="flex flex-col gap-3"
            >
              <p className="text-[12px] font-semibold">Filtros avanzados</p>

              {advanced.map((definition) => (
                <label key={definition.key} className="flex flex-col gap-1">
                  <span className="text-[11.5px] font-medium text-[var(--text-secondary)]">
                    {definition.label}
                  </span>

                  {definition.type === 'select' ? (
                    <Select
                      value={draft[definition.key] ?? ''}
                      onChange={(event) =>
                        setDraft((current) => ({ ...current, [definition.key]: event.target.value }))
                      }
                      className="h-8 text-[12.5px]"
                    >
                      <option value="">Todos</option>
                      {definition.options?.map((option) => (
                        <option key={option.value} value={option.value}>
                          {option.label}
                        </option>
                      ))}
                    </Select>
                  ) : (
                    <Input
                      type={definition.type === 'date' ? 'date' : 'text'}
                      value={draft[definition.key] ?? ''}
                      placeholder={definition.placeholder}
                      onChange={(event) =>
                        setDraft((current) => ({ ...current, [definition.key]: event.target.value }))
                      }
                      className="h-8 text-[12.5px]"
                    />
                  )}
                </label>
              ))}

              <div className="mt-1 flex justify-between gap-2">
                <Button type="button" variant="ghost" size="sm" onClick={clearAll}>
                  <X aria-hidden />
                  Limpiar
                </Button>
                <Button type="submit" variant="primary" size="sm">
                  Aplicar
                </Button>
              </div>
            </form>
          </PopoverContent>
        </Popover>
      )}

      {activeCount > 0 && (
        <Button variant="ghost" size="sm" onClick={clearAll}>
          <X aria-hidden />
          Quitar filtros
        </Button>
      )}

      {extra}
    </>
  );
}

function buildDraft(
  filters: Record<string, string | undefined>,
  definitions: FilterDefinition[],
): Record<string, string> {
  const draft: Record<string, string> = {};
  definitions.forEach((definition) => {
    const value = filters[definition.key];
    if (value) draft[definition.key] = value;
  });
  return draft;
}

function activeFilters(
  filters: Record<string, string | undefined>,
  definitions: FilterDefinition[],
): Record<string, string | undefined> {
  const active: Record<string, string | undefined> = {};
  definitions.forEach((definition) => {
    if (definition.key !== 'search' && filters[definition.key]) {
      active[definition.key] = filters[definition.key];
    }
  });
  return active;
}
