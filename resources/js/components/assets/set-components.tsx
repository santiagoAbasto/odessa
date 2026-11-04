import { router, useForm } from '@inertiajs/react';
import { Loader2, PackagePlus, Search, X } from 'lucide-react';
import { useEffect, useState, type FormEvent } from 'react';
import { Button } from '@/components/ui/button';
import { ConfirmButton } from '@/components/ui/confirm-button';
import { Dialog, DialogClose, DialogContent, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import type { SearchOption } from '@/components/ui/search-select';

/** Espera tras la última tecla antes de consultar. */
const DEBOUNCE_MS = 250;

/**
 * Tilda las piezas que componen un set.
 *
 * Se busca por texto (descripción, serie, código, número de parte) porque una
 * empresa puede tener miles de piezas, y lo tildado se conserva aunque cambie
 * la búsqueda: se puede buscar «cabezal», tildar, buscar «lubricador», tildar,
 * y agregar todo junto. Sólo se ofrecen piezas libres, de la misma empresa.
 */
export function AddComponentsDialog({ setId, hasComponents }: { setId: string; hasComponents: boolean }) {
  const [open, setOpen] = useState(false);
  const [search, setSearch] = useState('');
  const [options, setOptions] = useState<SearchOption[] | null>(null);
  // Lo tildado vive aparte de la lista visible: la lista cambia con cada búsqueda.
  const [picked, setPicked] = useState<Map<string, SearchOption>>(new Map());
  const { post, processing, errors, reset, transform } = useForm({ piece_public_ids: [] as string[] });

  transform(() => ({ piece_public_ids: [...picked.keys()] }));

  useEffect(() => {
    if (!open) return;

    const controller = new AbortController();
    const timer = setTimeout(() => {
      fetch(`/activos/${setId}/piezas-disponibles?q=${encodeURIComponent(search.trim())}`, {
        headers: { Accept: 'application/json' },
        signal: controller.signal,
      })
        .then((response) => (response.ok ? response.json() : { options: [] }))
        .then((payload) => setOptions(payload.options ?? []))
        .catch((error) => {
          if (error?.name !== 'AbortError') setOptions([]);
        });
    }, search ? DEBOUNCE_MS : 0);

    return () => {
      clearTimeout(timer);
      controller.abort();
    };
  }, [open, search, setId]);

  const toggle = (option: SearchOption) =>
    setPicked((current) => {
      const next = new Map(current);
      if (next.has(option.id)) next.delete(option.id);
      else next.set(option.id, option);
      return next;
    });

  const submit = (event: FormEvent) => {
    event.preventDefault();
    post(`/activos/${setId}/componentes`, {
      preserveScroll: true,
      onSuccess: () => {
        reset();
        setPicked(new Map());
        setSearch('');
        setOptions(null);
        setOpen(false);
      },
    });
  };

  return (
    <Dialog
      open={open}
      onOpenChange={(next) => {
        if (next) setOptions(null);
        setOpen(next);
      }}
    >
      <DialogTrigger asChild>
        <Button variant={hasComponents ? 'secondary' : 'primary'} size="sm">
          <PackagePlus aria-hidden />
          Agregar piezas
        </Button>
      </DialogTrigger>

      <DialogContent
        title="Agregar piezas al set"
        description="Busque y tilde las piezas que lo componen. Sólo aparecen las que todavía no pertenecen a ningún set."
        footer={
          <>
            <DialogClose asChild>
              <Button variant="secondary" size="sm">
                Cancelar
              </Button>
            </DialogClose>
            <Button
              type="submit"
              form="form-agregar-piezas"
              variant="primary"
              size="sm"
              loading={processing}
              disabled={picked.size === 0}
            >
              Agregar {picked.size || ''}
            </Button>
          </>
        }
      >
        <form id="form-agregar-piezas" onSubmit={submit} noValidate className="flex flex-col gap-3">
          <div className="relative">
            <Search
              className="pointer-events-none absolute top-1/2 left-2.5 size-3.5 -translate-y-1/2 text-[var(--text-muted)]"
              aria-hidden
            />
            <Input
              type="search"
              value={search}
              onChange={(event) => setSearch(event.target.value)}
              placeholder="Buscar por descripción, serie, código o número de parte…"
              aria-label="Buscar pieza"
              autoComplete="off"
              className="pl-8"
            />
          </div>

          {errors.piece_public_ids && (
            <p className="text-[11.5px] text-[var(--tone-danger-fg)]">{errors.piece_public_ids}</p>
          )}

          {picked.size > 0 && (
            <ul className="flex flex-wrap gap-1.5" aria-label="Piezas tildadas">
              {[...picked.values()].map((option) => (
                <li
                  key={option.id}
                  className="inline-flex max-w-full items-center gap-1 rounded-xs bg-[var(--surface-selected)] py-0.5 pr-1 pl-2 text-[11.5px] text-[var(--text-accent)]"
                >
                  <span className="truncate">{option.label}</span>
                  <button
                    type="button"
                    onClick={() => toggle(option)}
                    aria-label={`Destildar ${option.label}`}
                    className="grid size-4 shrink-0 place-items-center rounded-xs hover:bg-[var(--surface-hover)]"
                  >
                    <X className="size-3" aria-hidden />
                  </button>
                </li>
              ))}
            </ul>
          )}

          <div className="max-h-[300px] overflow-y-auto rounded-xs border border-[var(--border-default)]">
            {options === null ? (
              <p className="flex items-center justify-center gap-2 p-4 text-[12px] text-[var(--text-secondary)]">
                <Loader2 className="size-3.5 animate-spin" aria-hidden />
                Buscando…
              </p>
            ) : options.length === 0 ? (
              <p className="p-4 text-center text-[12px] text-[var(--text-secondary)]">
                {search.trim()
                  ? 'Ninguna pieza libre coincide con la búsqueda.'
                  : 'No hay piezas libres para agregar: todas ya pertenecen a un set.'}
              </p>
            ) : (
              options.map((option) => (
                <label
                  key={option.id}
                  className="flex cursor-pointer items-center gap-2.5 border-b border-[var(--border-subtle)] px-3 py-2 last:border-b-0 hover:bg-[var(--surface-hover)]"
                >
                  <input
                    type="checkbox"
                    checked={picked.has(option.id)}
                    onChange={() => toggle(option)}
                    className="size-3.5 shrink-0 rounded-xs accent-[var(--accent)]"
                  />
                  <span className="min-w-0 flex-1">
                    <span className="block truncate text-[12.5px] font-medium">{option.label}</span>
                    {option.code && (
                      <span className="block truncate font-mono text-[10.5px] text-[var(--text-muted)]">
                        {option.code}
                      </span>
                    )}
                  </span>
                </label>
              ))
            )}
          </div>
        </form>
      </DialogContent>
    </Dialog>
  );
}

/** Saca una pieza del set, con confirmación: el set se recalcula sin ella. */
export function RemoveComponentButton({
  setId,
  pieceId,
  name,
}: {
  setId: string;
  pieceId: string;
  name: string;
}) {
  return (
    <ConfirmButton
      variant="ghost"
      size="sm"
      title={`Quitar «${name}» del set`}
      description="La pieza queda suelta, con su historial intacto, y el estado del set se recalcula sin ella."
      confirmLabel="Quitar del set"
      onConfirm={() => router.delete(`/activos/${setId}/componentes/${pieceId}`, { preserveScroll: true })}
    >
      <X aria-hidden />
      <span className="sr-only">Quitar {name} del set</span>
    </ConfirmButton>
  );
}
