import { useForm } from '@inertiajs/react';
import { useEffect, useState, type FormEvent } from 'react';
import { Button } from '@/components/ui/button';
import { DialogClose, DialogContent } from '@/components/ui/dialog';
import { Field } from '@/components/ui/field';
import { Input, Select, Textarea } from '@/components/ui/input';
import type { SelectOption } from '@/types';

/**
 * Ingreso de un equipo que llegó sin envío previo del cliente.
 *
 * El circuito normal es que el cliente declare el envío y el taller lo
 * autorice, pero en la práctica llegan equipos sin nada cargado. Sin esta
 * pantalla, o no se trabajan o se trabajan fuera del sistema.
 *
 * Se pide lo mínimo para identificarlo: el equipo está físicamente en el
 * mostrador y quien lo recibe no puede quedarse completando una ficha entera.
 * El resto se edita después desde la ficha técnica.
 */
export function DirectReceptionDialog({
  organizations,
  onDone,
}: {
  organizations: SelectOption[];
  onDone: () => void;
}) {
  const hoy = new Date().toISOString().slice(0, 10);

  const { data, setData, post, processing, errors, reset } = useForm({
    organization_public_id: '',
    asset_public_id: '',
    description: '',
    serial_number: '',
    client_reference: '',
    kind: 'part',
    received_at: hoy,
    promised_at: '',
    is_urgent: false,
    notes: '',
  });

  /*
    Las piezas se cargan al elegir la empresa, no antes: el listado completo
    de todos los clientes sería enorme y además mezclaría inventarios.
  */
  const [assets, setAssets] = useState<SelectOption[]>([]);
  const [loadingAssets, setLoadingAssets] = useState(false);

  useEffect(() => {
    if (!data.organization_public_id) {
      setAssets([]);
      return;
    }

    let cancelled = false;
    setLoadingAssets(true);

    fetch(`/taller/recepciones/piezas/${data.organization_public_id}`, {
      headers: { Accept: 'application/json' },
    })
      .then((response) => (response.ok ? response.json() : []))
      .then((rows: SelectOption[]) => {
        if (!cancelled) setAssets(rows);
      })
      .catch(() => {
        if (!cancelled) setAssets([]);
      })
      .finally(() => {
        if (!cancelled) setLoadingAssets(false);
      });

    return () => {
      cancelled = true;
    };
  }, [data.organization_public_id]);

  const esPiezaNueva = data.asset_public_id === '';

  const submit = (event: FormEvent) => {
    event.preventDefault();
    post('/taller/recepciones', {
      preserveScroll: true,
      onSuccess: () => {
        reset();
        onDone();
      },
    });
  };

  return (
    <DialogContent
      title="Ingresar equipo"
      description="Para equipos que llegaron sin que el cliente cargara el envío."
      className="max-w-2xl"
      footer={
        <>
          <DialogClose asChild>
            <Button variant="secondary" size="sm">
              Cancelar
            </Button>
          </DialogClose>
          <Button type="submit" form="form-ingreso" variant="primary" size="sm" loading={processing}>
            Ingresar
          </Button>
        </>
      }
    >
      <form id="form-ingreso" onSubmit={submit} className="flex flex-col gap-4" noValidate>
        <Field
          label="Cliente"
          hint="De quién es el equipo. Define en qué inventario entra."
          error={errors.organization_public_id}
          required
        >
          {(props) => (
            <Select
              {...props}
              value={data.organization_public_id}
              onChange={(event) => {
                setData((current) => ({
                  ...current,
                  organization_public_id: event.target.value,
                  // Al cambiar de empresa, la pieza elegida ya no aplica.
                  asset_public_id: '',
                }));
              }}
              invalid={Boolean(errors.organization_public_id)}
            >
              <option value="">— Elija el cliente —</option>
              {organizations.map((option) => (
                <option key={option.value} value={option.value}>
                  {option.label}
                </option>
              ))}
            </Select>
          )}
        </Field>

        <Field
          label="Pieza"
          hint={
            loadingAssets
              ? 'Cargando el inventario del cliente…'
              : 'Si ya está registrada, elíjala. Si no, deje "Pieza nueva" y descríbala abajo.'
          }
          error={errors.asset_public_id}
        >
          {(props) => (
            <Select
              {...props}
              value={data.asset_public_id}
              disabled={!data.organization_public_id || loadingAssets}
              onChange={(event) => setData('asset_public_id', event.target.value)}
            >
              <option value="">Pieza nueva — dar de alta al ingresar</option>
              {assets.map((option) => (
                <option key={option.value} value={option.value}>
                  {option.label}
                </option>
              ))}
            </Select>
          )}
        </Field>

        {/* Sólo cuando se da de alta: si eligió una registrada, ya tiene datos. */}
        {esPiezaNueva && (
          <div className="grid gap-4 rounded-sm border border-[var(--border-subtle)] bg-[var(--surface-sunken)] p-3 sm:grid-cols-2">
            <div className="sm:col-span-2">
              <Field label="Descripción del equipo" error={errors.description} required>
                {(props) => (
                  <Input
                    {...props}
                    value={data.description}
                    maxLength={180}
                    onChange={(event) => setData('description', event.target.value)}
                    invalid={Boolean(errors.description)}
                  />
                )}
              </Field>
            </div>

            <Field label="Número de serie" error={errors.serial_number}>
              {(props) => (
                <Input
                  {...props}
                  value={data.serial_number}
                  maxLength={120}
                  onChange={(event) => setData('serial_number', event.target.value)}
                  invalid={Boolean(errors.serial_number)}
                />
              )}
            </Field>

            <Field label="Referencia del cliente" error={errors.client_reference}>
              {(props) => (
                <Input
                  {...props}
                  value={data.client_reference}
                  maxLength={120}
                  onChange={(event) => setData('client_reference', event.target.value)}
                />
              )}
            </Field>

            <Field label="Tipo" error={errors.kind}>
              {(props) => (
                <Select
                  {...props}
                  value={data.kind}
                  onChange={(event) => setData('kind', event.target.value)}
                >
                  <option value="part">Parte</option>
                  <option value="generic">Genérico</option>
                </Select>
              )}
            </Field>

            <p className="self-end pb-2 text-[11px] text-[var(--text-muted)] sm:col-span-1">
              La ficha técnica completa se carga después, desde el equipo.
            </p>
          </div>
        )}

        <div className="grid gap-4 sm:grid-cols-2">
          <Field label="Fecha de ingreso" error={errors.received_at} required>
            {(props) => (
              <Input
                {...props}
                type="date"
                value={data.received_at}
                max={hoy}
                onChange={(event) => setData('received_at', event.target.value)}
                invalid={Boolean(errors.received_at)}
              />
            )}
          </Field>

          <Field
            label="Fecha comprometida"
            hint="Opcional. Cuándo se promete devolverlo."
            error={errors.promised_at}
          >
            {(props) => (
              <Input
                {...props}
                type="date"
                value={data.promised_at}
                onChange={(event) => setData('promised_at', event.target.value)}
                invalid={Boolean(errors.promised_at)}
              />
            )}
          </Field>
        </div>

        <label className="flex items-center gap-2 text-[12.5px]">
          <input
            type="checkbox"
            checked={data.is_urgent}
            onChange={(event) => setData('is_urgent', event.target.checked)}
            className="size-3.5 accent-[var(--tone-danger-fg)]"
          />
          Marcar como urgente
        </label>

        <Field label="Observaciones" hint="Opcional." error={errors.notes}>
          {(props) => (
            <Textarea
              {...props}
              value={data.notes}
              maxLength={500}
              onChange={(event) => setData('notes', event.target.value)}
            />
          )}
        </Field>
      </form>
    </DialogContent>
  );
}
