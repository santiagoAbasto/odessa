import { Head, useForm } from '@inertiajs/react';
import { Plus, Settings2, ShieldCheck } from 'lucide-react';
import { useState, type FormEvent } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogClose, DialogContent, DialogTrigger } from '@/components/ui/dialog';
import { Field } from '@/components/ui/field';
import { Input, Select } from '@/components/ui/input';
import { Panel, PanelHeader } from '@/components/ui/panel';
import AppLayout from '@/layouts/app-layout';

interface Operation {
  id: number;
  code: string;
  name: string;
  determinesFitness: boolean;
  validityDays: number | null;
  turnaroundDays: number | null;
  /** Lo que se aplica cuando el campo está vacío. */
  effectiveTurnaround: number;
  isActive: boolean;
}

interface Defaults {
  turnaroundDays: number;
  validityDays: number;
  safetyMarginDays: number;
}

export default function Operations({
  operations,
  defaults,
}: {
  operations: Operation[];
  defaults: Defaults;
}) {
  const [creating, setCreating] = useState(false);
  const [editing, setEditing] = useState<Operation | null>(null);

  return (
    <AppLayout
      title="Operaciones"
      description="Qué hace el taller, cuánto tarda cada cosa y cuánto vale la certificación que emite."
      breadcrumbs={[{ label: 'Administración' }, { label: 'Operaciones' }]}
      actions={
        <Dialog open={creating} onOpenChange={setCreating}>
          <DialogTrigger asChild>
            <Button variant="primary" size="sm">
              <Plus aria-hidden />
              Nueva operación
            </Button>
          </DialogTrigger>
          <OperationDialog defaults={defaults} onDone={() => setCreating(false)} />
        </Dialog>
      }
    >
      <Head title="Operaciones" />

      {/*
        Estos dos números gobiernan la fecha límite de envío, así que conviene
        tenerlos a la vista al tocar los tiempos de cada operación.
      */}
      <Panel className="mb-5">
        <div className="grid gap-px bg-[var(--border-subtle)] sm:grid-cols-3">
          <Metric label="Tiempo de taller por defecto" value={`${defaults.turnaroundDays} días`} />
          <Metric label="Margen de seguridad" value={`${defaults.safetyMarginDays} días`} />
          <Metric label="Vigencia por defecto" value={`${defaults.validityDays} días`} />
        </div>
        <p className="border-t border-[var(--border-subtle)] px-4 py-2.5 text-[11.5px] text-[var(--text-muted)]">
          La fecha límite de envío de un equipo es su vencimiento menos el tiempo de taller
          menos el margen. Una operación sin tiempo propio usa el valor por defecto.
        </p>
      </Panel>

      <Panel>
        <PanelHeader
          title="Tabla de operaciones"
          description={`${operations.length} operaciones registradas.`}
        />

        <div className="overflow-x-auto">
          <table className="w-full min-w-[820px] text-[12.5px]">
            <thead>
              <tr>
                {['Operación', 'Tipo', 'Tarda', 'Vigencia que emite', 'Estado', ''].map((header) => (
                  <th
                    key={header}
                    scope="col"
                    className="h-9 border-b border-[var(--border-default)] bg-[var(--surface-sunken)] px-3 text-left text-[10px] font-semibold tracking-[0.08em] text-[var(--text-secondary)] uppercase"
                  >
                    {header}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {operations.map((operation) => (
                <tr
                  key={operation.id}
                  className="border-b border-[var(--border-subtle)] last:border-b-0 hover:bg-[var(--surface-hover)]"
                >
                  <td className="px-3 py-2.5">
                    <p className="font-medium">{operation.name}</p>
                    <p className="mt-0.5 font-mono text-[10.5px] text-[var(--text-muted)]">
                      {operation.code}
                    </p>
                  </td>
                  <td className="px-3 py-2.5">
                    {operation.determinesFitness ? (
                      <span className="inline-flex items-center gap-1.5 text-[var(--tone-success-fg)]">
                        <ShieldCheck className="size-3.5" aria-hidden />
                        Certifica
                      </span>
                    ) : (
                      <span className="text-[var(--text-muted)]">Sólo registra</span>
                    )}
                  </td>
                  <td className="px-3 py-2.5 tabular">
                    {operation.turnaroundDays ? (
                      `${operation.turnaroundDays} días`
                    ) : (
                      <span className="text-[var(--text-muted)]">
                        {operation.effectiveTurnaround} días · por defecto
                      </span>
                    )}
                  </td>
                  <td className="px-3 py-2.5 tabular">
                    {operation.determinesFitness
                      ? operation.validityDays
                        ? `${operation.validityDays} días`
                        : `${defaults.validityDays} días · por defecto`
                      : '—'}
                  </td>
                  <td className="px-3 py-2.5">
                    <Badge tone={operation.isActive ? 'success' : 'neutral'}>
                      {operation.isActive ? 'Activa' : 'Inactiva'}
                    </Badge>
                  </td>
                  <td className="px-3 py-2.5 text-right">
                    <Button variant="secondary" size="sm" onClick={() => setEditing(operation)}>
                      <Settings2 aria-hidden />
                      Editar
                    </Button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </Panel>

      <Dialog open={editing !== null} onOpenChange={(open) => !open && setEditing(null)}>
        {editing && (
          <OperationDialog
            operation={editing}
            defaults={defaults}
            onDone={() => setEditing(null)}
          />
        )}
      </Dialog>
    </AppLayout>
  );
}

function Metric({ label, value }: { label: string; value: string }) {
  return (
    <div className="bg-[var(--surface-raised)] px-4 py-3">
      <p className="text-[10px] font-semibold tracking-[0.1em] text-[var(--text-muted)] uppercase">
        {label}
      </p>
      <p className="mt-1 text-[16px] leading-none font-semibold tabular">{value}</p>
    </div>
  );
}

function OperationDialog({
  operation,
  defaults,
  onDone,
}: {
  operation?: Operation;
  defaults: Defaults;
  onDone: () => void;
}) {
  const isEdit = operation !== undefined;

  const { data, setData, post, put, processing, errors } = useForm({
    name: operation?.name ?? '',
    code: operation?.code ?? '',
    determines_fitness: operation?.determinesFitness ?? true,
    validity_days: operation?.validityDays?.toString() ?? '',
    turnaround_days: operation?.turnaroundDays?.toString() ?? '',
    is_active: operation?.isActive ?? true,
  });

  const submit = (event: FormEvent) => {
    event.preventDefault();

    const options = { onSuccess: onDone, preserveScroll: true };

    if (isEdit) {
      put(`/administracion/operaciones/${operation.id}`, options);
    } else {
      post('/administracion/operaciones', options);
    }
  };

  return (
    <DialogContent
      title={isEdit ? `Editar «${operation.name}»` : 'Nueva operación'}
      description="El tiempo que tarda define con cuánta anticipación se le avisa al cliente."
      className="max-w-lg"
      footer={
        <>
          <DialogClose asChild>
            <Button variant="secondary" size="sm">
              Cancelar
            </Button>
          </DialogClose>
          <Button type="submit" form="form-operacion" variant="primary" size="sm" loading={processing}>
            {isEdit ? 'Guardar' : 'Crear'}
          </Button>
        </>
      }
    >
      <form id="form-operacion" onSubmit={submit} className="flex flex-col gap-4" noValidate>
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label="Nombre" error={errors.name} required>
            {(props) => (
              <Input
                {...props}
                value={data.name}
                maxLength={120}
                onChange={(event) => setData('name', event.target.value)}
                invalid={Boolean(errors.name)}
              />
            )}
          </Field>

          <Field label="Código" error={errors.code} required>
            {(props) => (
              <Input
                {...props}
                value={data.code}
                maxLength={20}
                onChange={(event) => setData('code', event.target.value)}
                invalid={Boolean(errors.code)}
              />
            )}
          </Field>
        </div>

        <Field
          label="Tipo de operación"
          hint="Sólo las que certifican emiten dictamen Apto / No Apto y renuevan la vigencia."
          error={errors.determines_fitness}
        >
          {(props) => (
            <Select
              {...props}
              value={data.determines_fitness ? '1' : '0'}
              onChange={(event) => setData('determines_fitness', event.target.value === '1')}
            >
              <option value="1">Certifica — emite dictamen y renueva vigencia</option>
              <option value="0">Sólo registra — queda en la bitácora</option>
            </Select>
          )}
        </Field>

        <div className="grid gap-4 sm:grid-cols-2">
          <Field
            label="Días que tarda"
            hint={`Vacío usa ${defaults.turnaroundDays}.`}
            error={errors.turnaround_days}
          >
            {(props) => (
              <Input
                {...props}
                type="number"
                min={1}
                max={365}
                value={data.turnaround_days}
                onChange={(event) => setData('turnaround_days', event.target.value)}
                invalid={Boolean(errors.turnaround_days)}
              />
            )}
          </Field>

          <Field
            label="Vigencia que emite"
            hint={
              data.determines_fitness
                ? `En días. Vacío usa ${defaults.validityDays}.`
                : 'No aplica: esta operación no certifica.'
            }
            error={errors.validity_days}
          >
            {(props) => (
              <Input
                {...props}
                type="number"
                min={1}
                max={3650}
                value={data.validity_days}
                disabled={!data.determines_fitness}
                onChange={(event) => setData('validity_days', event.target.value)}
                invalid={Boolean(errors.validity_days)}
              />
            )}
          </Field>
        </div>

        {isEdit && (
          <Field label="Estado" error={errors.is_active}>
            {(props) => (
              <Select
                {...props}
                value={data.is_active ? '1' : '0'}
                onChange={(event) => setData('is_active', event.target.value === '1')}
              >
                <option value="1">Activa</option>
                <option value="0">Inactiva — no se ofrece en informes nuevos</option>
              </Select>
            )}
          </Field>
        )}
      </form>
    </DialogContent>
  );
}
