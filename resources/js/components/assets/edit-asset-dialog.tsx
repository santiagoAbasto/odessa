import { useForm } from '@inertiajs/react';
import { type FormEvent } from 'react';
import { Button } from '@/components/ui/button';
import { DialogClose, DialogContent } from '@/components/ui/dialog';
import { Field } from '@/components/ui/field';
import { Input, Select } from '@/components/ui/input';
import type { SelectOption } from '@/types';

export interface EditableAsset {
  id: string;
  kind: string;
  description: string | null;
  workshopDescription: string | null;
  serialNumber: string | null;
  clientReference: string | null;
  manufacturerReference: string | null;
  brand: string | null;
  model: string | null;
  identifier: string | null;
  catalogItemId: number | null;
  manufacturerId: number | null;
  testPressureId: number | null;
  workingPressureId: number | null;
  serviceId: number | null;
  connectionTopId: number | null;
  connectionBottomId: number | null;
  connectionSide1Id: number | null;
  connectionSide2Id: number | null;
  parent: { id: string } | null;
}

export interface EditCatalogs {
  catalogItems: SelectOption[];
  manufacturers: SelectOption[];
  pressures: SelectOption[];
  connections: SelectOption[];
  services: SelectOption[];
  possibleParents: SelectOption[];
}

/** Convierte '' del <select> al null que espera el backend. */
const orNull = (value: string) => (value === '' ? null : value);

export function EditAssetDialog({
  asset,
  catalogs,
  onDone,
}: {
  asset: EditableAsset;
  catalogs: EditCatalogs;
  onDone: () => void;
}) {
  const isGeneric = asset.kind === 'generic';

  const { data, setData, put, processing, errors, transform } = useForm({
    description: asset.description ?? '',
    workshop_description: asset.workshopDescription ?? '',
    serial_number: asset.serialNumber ?? '',
    client_reference: asset.clientReference ?? '',
    manufacturer_reference: asset.manufacturerReference ?? '',
    brand: asset.brand ?? '',
    model: asset.model ?? '',
    identifier: asset.identifier ?? '',
    catalog_item_id: asset.catalogItemId?.toString() ?? '',
    manufacturer_id: asset.manufacturerId?.toString() ?? '',
    test_pressure_id: asset.testPressureId?.toString() ?? '',
    working_pressure_id: asset.workingPressureId?.toString() ?? '',
    service_id: asset.serviceId?.toString() ?? '',
    connection_top_id: asset.connectionTopId?.toString() ?? '',
    connection_bottom_id: asset.connectionBottomId?.toString() ?? '',
    connection_side_1_id: asset.connectionSide1Id?.toString() ?? '',
    connection_side_2_id: asset.connectionSide2Id?.toString() ?? '',
    parent_public_id: asset.parent?.id ?? '',
  });

  const submit = (event: FormEvent) => {
    event.preventDefault();

    // Los desplegables vacíos tienen que llegar como null, no como '':
    // `exists:` rechazaría la cadena vacía y `nullable` no la alcanza.
    transform((current) =>
      Object.fromEntries(
        Object.entries(current).map(([key, value]) =>
          key.endsWith('_id') ? [key, orNull(value as string)] : [key, value],
        ),
      ) as typeof current,
    );

    put(`/activos/${asset.id}`, {
      preserveScroll: true,
      onSuccess: onDone,
    });
  };

  const optionField = (
    label: string,
    key: keyof typeof data,
    options: SelectOption[],
    hint?: string,
  ) => (
    <Field label={label} hint={hint} error={errors[key as keyof typeof errors]}>
      {(props) => (
        <Select
          {...props}
          value={data[key] as string}
          onChange={(event) => setData(key, event.target.value)}
        >
          <option value="">— Sin definir —</option>
          {options.map((option) => (
            <option key={option.value} value={option.value}>
              {option.label}
            </option>
          ))}
        </Select>
      )}
    </Field>
  );

  return (
    <DialogContent
      title="Editar ficha técnica"
      description="Los datos de la ficha condicionan el mantenimiento y los plazos. Cada cambio queda registrado en la auditoría."
      className="max-w-3xl"
      footer={
        <>
          <DialogClose asChild>
            <Button variant="secondary" size="sm">
              Cancelar
            </Button>
          </DialogClose>
          <Button type="submit" form="form-ficha" variant="primary" size="sm" loading={processing}>
            Guardar cambios
          </Button>
        </>
      }
    >
      <form id="form-ficha" onSubmit={submit} className="flex flex-col gap-5" noValidate>
        <section className="grid gap-4 sm:grid-cols-2">
          <Field label="Descripción" error={errors.description} required>
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

          <Field
            label="Descripción del taller"
            hint="Cómo lo llama el taller, si difiere."
            error={errors.workshop_description}
          >
            {(props) => (
              <Input
                {...props}
                value={data.workshop_description}
                maxLength={180}
                onChange={(event) => setData('workshop_description', event.target.value)}
              />
            )}
          </Field>

          <Field label="Número de serie" error={errors.serial_number}>
            {(props) => (
              <Input
                {...props}
                value={data.serial_number}
                maxLength={120}
                onChange={(event) => setData('serial_number', event.target.value)}
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
        </section>

        <section>
          <h3 className="mb-3 text-[10px] font-semibold tracking-[0.1em] text-[var(--text-muted)] uppercase">
            Clasificación
          </h3>
          <div className="grid gap-4 sm:grid-cols-2">
            {optionField(
              'Tipo de equipo',
              'catalog_item_id',
              catalogs.catalogItems,
              'Determina el tiempo de taller y, con él, la fecha límite de envío.',
            )}
            {optionField('Servicio', 'service_id', catalogs.services)}
            {optionField('Fabricante', 'manufacturer_id', catalogs.manufacturers)}

            <Field label="Referencia del fabricante" error={errors.manufacturer_reference}>
              {(props) => (
                <Input
                  {...props}
                  value={data.manufacturer_reference}
                  maxLength={120}
                  onChange={(event) => setData('manufacturer_reference', event.target.value)}
                />
              )}
            </Field>

            {isGeneric && (
              <>
                <Field label="Marca" error={errors.brand}>
                  {(props) => (
                    <Input
                      {...props}
                      value={data.brand}
                      maxLength={120}
                      onChange={(event) => setData('brand', event.target.value)}
                    />
                  )}
                </Field>

                <Field label="Modelo" error={errors.model}>
                  {(props) => (
                    <Input
                      {...props}
                      value={data.model}
                      maxLength={120}
                      onChange={(event) => setData('model', event.target.value)}
                    />
                  )}
                </Field>
              </>
            )}
          </div>
        </section>

        {!isGeneric && (
          <section>
            <h3 className="mb-3 text-[10px] font-semibold tracking-[0.1em] text-[var(--text-muted)] uppercase">
              Presiones y conexiones
            </h3>
            <div className="grid gap-4 sm:grid-cols-2">
              {optionField('Presión de prueba', 'test_pressure_id', catalogs.pressures)}
              {optionField('Presión de trabajo', 'working_pressure_id', catalogs.pressures)}
              {optionField('Conexión superior', 'connection_top_id', catalogs.connections)}
              {optionField('Conexión inferior', 'connection_bottom_id', catalogs.connections)}
              {optionField('Conexión lateral 1', 'connection_side_1_id', catalogs.connections)}
              {optionField('Conexión lateral 2', 'connection_side_2_id', catalogs.connections)}
            </div>
          </section>
        )}

        <section>
          <h3 className="mb-3 text-[10px] font-semibold tracking-[0.1em] text-[var(--text-muted)] uppercase">
            Equipo compuesto
          </h3>
          {optionField(
            'Forma parte de',
            'parent_public_id',
            catalogs.possibleParents,
            'Si esta pieza es componente de un conjunto, elíjalo aquí. El conjunto queda bloqueado mientras alguno de sus componentes no esté apto.',
          )}
        </section>
      </form>
    </DialogContent>
  );
}
