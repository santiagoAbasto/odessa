import { router, useForm } from '@inertiajs/react';
import { ClipboardCheck, PackagePlus, Search } from 'lucide-react';
import { useEffect, useState, type FormEvent } from 'react';
import { Button } from '@/components/ui/button';
import { Dialog, DialogClose, DialogContent, DialogTrigger } from '@/components/ui/dialog';
import { Field } from '@/components/ui/field';
import { Input, Select, Textarea } from '@/components/ui/input';
import { Skeleton } from '@/components/ui/skeleton';

interface AvailableAsset {
  id: string;
  name: string;
  serialNumber: string | null;
  code: string | null;
}

/**
 * Suma equipos al informe.
 *
 * Los trae por fetch al abrirse y no como prop de la página: son el inventario
 * entero de la empresa y cargarlo en cada visita al informe —que la mayoría de
 * las veces es sólo para mirarlo— sería pagar por algo que casi nunca se usa.
 */
export function AddAssetsDialog({ reportId }: { reportId: string }) {
  const [open, setOpen] = useState(false);
  const [assets, setAssets] = useState<AvailableAsset[] | null>(null);
  const [search, setSearch] = useState('');
  const { data, setData, post, processing, errors, reset } = useForm({
    asset_public_ids: [] as string[],
  });

  // Cada apertura arranca de cero: mientras llega la primera tanda se muestra «cargando».
  useEffect(() => {
    if (open) setAssets(null);
  }, [open]);

  /*
    La búsqueda se hace en el servidor: una empresa puede tener cientos o miles
    de equipos y traerlos todos (con un tope) dejaba a los últimos sin poder
    agregarse a ningún informe. Se consulta con una breve espera tras la última
    tecla y se cancela la consulta anterior, para que una respuesta lenta no pise
    a una más nueva. Lo tildado vive en `data`, aparte de la lista visible.
  */
  useEffect(() => {
    if (!open) return;

    const controller = new AbortController();
    const timer = setTimeout(
      () => {
        fetch(`/informes/${reportId}/equipos-disponibles?q=${encodeURIComponent(search.trim())}`, {
          headers: { Accept: 'application/json' },
          signal: controller.signal,
        })
          .then((response) => response.json())
          .then((payload) => setAssets(payload.assets ?? []))
          .catch((error) => {
            if (error?.name !== 'AbortError') setAssets([]);
          });
      },
      search ? 250 : 0,
    );

    return () => {
      clearTimeout(timer);
      controller.abort();
    };
  }, [open, reportId, search]);

  const visibles = assets ?? [];

  const submit = (event: FormEvent) => {
    event.preventDefault();
    post(`/informes/${reportId}/equipos`, {
      preserveScroll: true,
      onSuccess: () => {
        reset();
        setSearch('');
        setOpen(false);
      },
    });
  };

  const alternar = (id: string) =>
    setData(
      'asset_public_ids',
      data.asset_public_ids.includes(id)
        ? data.asset_public_ids.filter((value) => value !== id)
        : [...data.asset_public_ids, id],
    );

  return (
    <Dialog open={open} onOpenChange={setOpen}>
      <DialogTrigger asChild>
        <Button variant="secondary" size="sm">
          <PackagePlus aria-hidden />
          Agregar equipos
        </Button>
      </DialogTrigger>

      <DialogContent
        title="Agregar equipos al informe"
        description="Entran sin dictamen. Después se resuelven, de a uno o en tanda."
        footer={
          <>
            <DialogClose asChild>
              <Button variant="secondary" size="sm">
                Cancelar
              </Button>
            </DialogClose>
            <Button
              type="submit"
              form="form-agregar-equipos"
              variant="primary"
              size="sm"
              loading={processing}
              disabled={data.asset_public_ids.length === 0}
            >
              Agregar {data.asset_public_ids.length || ''}
            </Button>
          </>
        }
      >
        <form id="form-agregar-equipos" onSubmit={submit} noValidate className="flex flex-col gap-3">
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
              aria-label="Buscar equipo"
              autoComplete="off"
              className="pl-8"
            />
          </div>
          <p className="-mt-1 text-[11px] text-[var(--text-muted)]">
            Se muestran hasta 50. Escriba para encontrar uno puntual; lo que tilde se conserva al buscar otra cosa.
          </p>

          {errors.asset_public_ids && (
            <p className="text-[11.5px] text-[var(--tone-danger-fg)]">{errors.asset_public_ids}</p>
          )}

          <div className="max-h-[320px] overflow-y-auto rounded-xs border border-[var(--border-default)]">
            {assets === null ? (
              <div className="flex flex-col gap-2 p-3">
                <Skeleton className="h-6" />
                <Skeleton className="h-6" />
                <Skeleton className="h-6" />
              </div>
            ) : visibles.length === 0 ? (
              <p className="p-4 text-center text-[12px] text-[var(--text-secondary)]">
                {search.trim()
                  ? 'Ningún equipo coincide con la búsqueda.'
                  : 'Esta empresa no tiene equipos fuera de este informe.'}
              </p>
            ) : (
              visibles.map((asset) => (
                <label
                  key={asset.id}
                  className="flex cursor-pointer items-center gap-2.5 border-b border-[var(--border-subtle)] px-3 py-2 last:border-b-0 hover:bg-[var(--surface-hover)]"
                >
                  <input
                    type="checkbox"
                    checked={data.asset_public_ids.includes(asset.id)}
                    onChange={() => alternar(asset.id)}
                    className="size-3.5 shrink-0 rounded-xs accent-[var(--accent)]"
                  />
                  <span className="min-w-0 flex-1">
                    <span className="block truncate text-[12.5px] font-medium">{asset.name}</span>
                    <span className="block truncate font-mono text-[10.5px] text-[var(--text-muted)]">
                      {[asset.serialNumber, asset.code].filter(Boolean).join(' · ') || '—'}
                    </span>
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

/**
 * Dictamina en tanda las líneas seleccionadas.
 *
 * El negocio lo pidió así —«pueden aprobar a un equipo varios»— porque un FMR
 * típico resuelve una tanda entera con el mismo resultado, y cargarlos de a
 * uno es trabajo repetido.
 *
 * El vencimiento puede quedar vacío: al finalizar el informe se calcula desde
 * la vigencia de la operación. Sólo se completa cuando el certificado trae una
 * fecha propia.
 */
export function ResolveLinesDialog({
  reportId,
  lineIds,
  finalized = false,
  onDone,
}: {
  reportId: string;
  lineIds: number[];
  /** Informe ya final: guardar recalcula el estado de las piezas en el acto. */
  finalized?: boolean;
  onDone: () => void;
}) {
  const [open, setOpen] = useState(false);
  const { data, setData, post, processing, errors, reset } = useForm({
    line_ids: lineIds,
    result: 'apto',
    expires_at: '',
    findings: '',
  });

  // El diálogo vive mientras cambia la selección de la tabla.
  useEffect(() => setData('line_ids', lineIds), [lineIds]);

  const submit = (event: FormEvent) => {
    event.preventDefault();
    post(`/informes/${reportId}/dictamen`, {
      preserveScroll: true,
      onSuccess: () => {
        reset();
        setOpen(false);
        onDone();
      },
    });
  };

  const certifica = data.result === 'apto';

  return (
    <Dialog open={open} onOpenChange={setOpen}>
      <DialogTrigger asChild>
        <Button variant="primary" size="sm" disabled={lineIds.length === 0}>
          <ClipboardCheck aria-hidden />
          Dictaminar {lineIds.length || ''}
        </Button>
      </DialogTrigger>

      <DialogContent
        title={`Dictaminar ${lineIds.length} ${lineIds.length === 1 ? 'equipo' : 'equipos'}`}
        description={
          finalized
            ? 'El informe ya está finalizado: al guardar se recalcula el estado de estas piezas y queda en la auditoría.'
            : 'El estado de las piezas no cambia hasta que se finalice el informe.'
        }
        footer={
          <>
            <DialogClose asChild>
              <Button variant="secondary" size="sm">
                Cancelar
              </Button>
            </DialogClose>
            <Button type="submit" form="form-dictamen" variant="primary" size="sm" loading={processing}>
              Guardar dictamen
            </Button>
          </>
        }
      >
        <form id="form-dictamen" onSubmit={submit} className="flex flex-col gap-4" noValidate>
          <Field label="Resultado" error={errors.result} required>
            {(props) => (
              <Select
                {...props}
                value={data.result}
                onChange={(event) => setData('result', event.target.value)}
                invalid={Boolean(errors.result)}
              >
                <option value="apto">Apto</option>
                <option value="no_apto">No apto</option>
                <option value="no_aplica">No aplica</option>
                <option value="sin_procesar">Sin procesar</option>
              </Select>
            )}
          </Field>

          {/* Sólo un "apto" certifica, así que sólo ahí tiene sentido una
              vigencia. En los demás casos el servidor la descarta igual. */}
          {certifica && (
            <Field
              label="Vencimiento del certificado"
              hint="Si se deja vacío, al finalizar se calcula desde la vigencia de la operación."
              error={errors.expires_at}
            >
              {(props) => (
                <Input
                  {...props}
                  type="date"
                  value={data.expires_at}
                  onChange={(event) => setData('expires_at', event.target.value)}
                  invalid={Boolean(errors.expires_at)}
                />
              )}
            </Field>
          )}

          <Field
            label="Observaciones"
            hint={certifica ? undefined : 'Qué se encontró. Es lo que después justifica la reparación.'}
            error={errors.findings}
          >
            {(props) => (
              <Textarea
                {...props}
                value={data.findings}
                rows={3}
                maxLength={2000}
                onChange={(event) => setData('findings', event.target.value)}
              />
            )}
          </Field>
        </form>
      </DialogContent>
    </Dialog>
  );
}

/** Quita del informe un equipo cargado por error. */
export function removeAssetFromReport(reportId: string, assetId: string): void {
  router.delete(`/informes/${reportId}/equipos/${assetId}`, { preserveScroll: true });
}
