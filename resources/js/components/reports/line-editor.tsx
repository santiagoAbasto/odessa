import { router, useForm } from '@inertiajs/react';
import { ClipboardCheck, PackagePlus, Search } from 'lucide-react';
import { useEffect, useMemo, useState, type FormEvent } from 'react';
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

  useEffect(() => {
    if (!open) return;

    setAssets(null);
    fetch(`/informes/${reportId}/equipos-disponibles`, {
      headers: { Accept: 'application/json' },
    })
      .then((response) => response.json())
      .then((payload) => setAssets(payload.assets ?? []))
      .catch(() => setAssets([]));
  }, [open, reportId]);

  const visibles = useMemo(() => {
    if (assets === null) return [];
    const termino = search.trim().toLowerCase();
    if (!termino) return assets;

    return assets.filter((asset) =>
      [asset.name, asset.serialNumber, asset.code]
        .filter(Boolean)
        .some((campo) => String(campo).toLowerCase().includes(termino)),
    );
  }, [assets, search]);

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
              placeholder="Buscar por descripción, serie o código…"
              aria-label="Buscar equipo"
              className="pl-8"
            />
          </div>

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
                {assets.length === 0
                  ? 'Esta empresa no tiene equipos fuera de este informe.'
                  : 'Ningún equipo coincide con la búsqueda.'}
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
  onDone,
}: {
  reportId: string;
  lineIds: number[];
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
        description="El estado de las piezas no cambia hasta que se finalice el informe."
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
