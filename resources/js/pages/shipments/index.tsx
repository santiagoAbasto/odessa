import { Deferred, Head, Link, router, useForm } from '@inertiajs/react';
import { Plus, Truck } from 'lucide-react';
import { useState, type FormEvent } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogClose, DialogContent, DialogTrigger } from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { Field } from '@/components/ui/field';
import { Input, Select, Textarea } from '@/components/ui/input';
import { Panel } from '@/components/ui/panel';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/cn';
import { formatDate, formatDateTime } from '@/lib/format';
import type { Paginated, SelectOption, Tone } from '@/types';

interface ShipmentRow {
  id: string;
  reference: string;
  clientReference: string | null;
  status: string;
  statusLabel: string;
  statusTone: Tone;
  itemsCount: number;
  expectedAt: string | null;
  organization: string | null;
  createdBy: string | null;
  createdAt: string | null;
  reviewedBy: string | null;
  reviewedAt: string | null;
}

interface Prefill {
  organization: string | null;
  nextReference: string | null;
  contactName: string | null;
  contactPhone: string | null;
}

export default function ShipmentsIndex({
  shipments,
  filters,
  statusOptions,
  can,
  prefill,
  pendingCount,
}: {
  shipments: Paginated<ShipmentRow>;
  filters: Record<string, string | undefined>;
  statusOptions: SelectOption[];
  can: { create: boolean; authorize: boolean };
  prefill: Prefill;
  pendingCount?: number;
}) {
  const [creating, setCreating] = useState(false);

  return (
    <AppLayout
      title="Envíos al taller"
      description={
        can.authorize
          ? 'Equipos que los clientes declaran antes de mandarlos. Hasta que el taller les da recepción, nada entra al taller.'
          : 'Declare aquí los equipos que envía. El taller les da recepción cuando llegan.'
      }
      breadcrumbs={[{ label: 'Envíos al taller' }]}
      actions={
        can.create && (
          <Dialog open={creating} onOpenChange={setCreating}>
            <DialogTrigger asChild>
              <Button variant="primary" size="sm">
                <Plus aria-hidden />
                Nuevo envío
              </Button>
            </DialogTrigger>
            <CreateShipmentDialog prefill={prefill} onDone={() => setCreating(false)} />
          </Dialog>
        )
      }
    >
      <Head title="Envíos al taller" />

      {/*
        Para el taller, lo pendiente es la cola de trabajo: va arriba.
        Inertia exige un fallback con valor —`null` es falsy y lanza error—,
        y aquí no hay nada que mostrar mientras carga: va un fragmento.
      */}
      {can.authorize && (
        <Deferred data="pendingCount" fallback={<></>}>
          <PendingBanner count={pendingCount ?? 0} />
        </Deferred>
      )}

      <Panel>
        <div className="flex flex-wrap items-center gap-2 border-b border-[var(--border-subtle)] px-3 py-2.5">
          <Input
            type="search"
            defaultValue={filters.search ?? ''}
            onChange={(event) =>
              router.get(
                '/envios',
                { ...filters, search: event.target.value || undefined },
                { preserveState: true, preserveScroll: true, replace: true },
              )
            }
            placeholder="Buscar por referencia o remito…"
            aria-label="Buscar envíos"
            className="h-8 max-w-xs text-[12.5px]"
          />

          <Select
            value={filters.status ?? ''}
            onChange={(event) =>
              router.get(
                '/envios',
                { ...filters, status: event.target.value || undefined },
                { preserveState: true, preserveScroll: true, replace: true },
              )
            }
            aria-label="Filtrar por estado"
            className="h-8 w-auto text-[12.5px]"
          >
            <option value="">Todos los estados</option>
            {statusOptions.map((option) => (
              <option key={option.value} value={option.value}>
                {option.label}
              </option>
            ))}
          </Select>
        </div>

        {shipments.data.length === 0 ? (
          <EmptyState
            icon={Truck}
            title="Sin envíos"
            description={
              can.create
                ? 'Cree un envío para declarar los equipos que manda al taller.'
                : 'Todavía no hay envíos declarados.'
            }
          />
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full min-w-[720px] text-[12.5px]">
              <thead>
                <tr>
                  {['Referencia', ...(can.authorize ? ['Empresa'] : []), 'Equipos', 'Llegada estimada', 'Estado', 'Revisión'].map(
                    (header) => (
                      <th
                        key={header}
                        scope="col"
                        className="h-9 border-b border-[var(--border-default)] bg-[var(--surface-sunken)] px-3 text-left text-[10px] font-semibold tracking-[0.08em] text-[var(--text-secondary)] uppercase"
                      >
                        {header}
                      </th>
                    ),
                  )}
                </tr>
              </thead>
              <tbody>
                {shipments.data.map((shipment) => (
                  <tr
                    key={shipment.id}
                    onClick={() => router.visit(`/envios/${shipment.id}`)}
                    className="cursor-pointer border-b border-[var(--border-subtle)] last:border-b-0 hover:bg-[var(--surface-hover)]"
                  >
                    <td className="px-3 py-2.5">
                      <span className="font-mono font-semibold text-[var(--text-accent)]">
                        {shipment.reference}
                      </span>
                      {shipment.clientReference && (
                        <p className="mt-0.5 font-mono text-[10.5px] text-[var(--text-muted)]">
                          Remito {shipment.clientReference}
                        </p>
                      )}
                    </td>
                    {can.authorize && (
                      <td className="px-3 py-2.5 text-[var(--text-secondary)]">
                        {shipment.organization ?? '—'}
                      </td>
                    )}
                    <td className="px-3 py-2.5 tabular">{shipment.itemsCount}</td>
                    <td className="px-3 py-2.5 tabular">{formatDate(shipment.expectedAt)}</td>
                    <td className="px-3 py-2.5">
                      <Badge tone={shipment.statusTone}>{shipment.statusLabel}</Badge>
                    </td>
                    <td className="px-3 py-2.5 text-[11.5px] text-[var(--text-muted)]">
                      {shipment.reviewedAt
                        ? `${shipment.reviewedBy ?? ''} · ${formatDateTime(shipment.reviewedAt)}`
                        : '—'}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Panel>
    </AppLayout>
  );
}

function PendingBanner({ count }: { count: number }) {
  if (count === 0) return null;

  return (
    <Link
      href="/envios?status=submitted"
      className={cn(
        'mb-4 flex items-center gap-3 rounded-sm border px-4 py-3 transition-colors',
        'border-[var(--tone-info-fg)]/30 bg-[var(--tone-info-bg)] hover:brightness-[0.98]',
      )}
    >
      <Truck className="size-4 shrink-0 text-[var(--tone-info-fg)]" aria-hidden />
      <p className="text-[13px] text-[var(--tone-info-fg)]">
        <strong>{count}</strong> {count === 1 ? 'envío espera' : 'envíos esperan'} recepción en el taller.
      </p>
    </Link>
  );
}

function CreateShipmentDialog({ prefill, onDone }: { prefill: Prefill; onDone: () => void }) {
  const { data, setData, post, processing, errors, reset } = useForm({
    client_reference: '',
    expected_at: '',
    // Se autocompletan con los datos del usuario, pero son editables:
    // quien carga el envío no siempre es quien lo despacha físicamente.
    contact_name: prefill.contactName ?? '',
    contact_phone: prefill.contactPhone ?? '',
    carrier: '',
    origin: '',
    notes: '',
  });

  const submit = (event: FormEvent) => {
    event.preventDefault();
    post('/envios', {
      onSuccess: () => {
        reset();
        onDone();
      },
    });
  };

  return (
    <DialogContent
      title="Nuevo envío"
      description="Complete los datos del envío. Después agregue los equipos y remítalo al taller."
      className="max-w-2xl"
      footer={
        <>
          <DialogClose asChild>
            <Button variant="secondary" size="sm">
              Cancelar
            </Button>
          </DialogClose>
          <Button type="submit" form="form-envio" variant="primary" size="sm" loading={processing}>
            Crear envío
          </Button>
        </>
      }
    >
      <form id="form-envio" onSubmit={submit} className="flex flex-col gap-4" noValidate>
        {/* Lo que el sistema ya sabe: se muestra, no se pide. */}
        <div className="grid gap-3 rounded-sm border border-[var(--border-subtle)] bg-[var(--surface-sunken)] p-3 sm:grid-cols-2">
          <div>
            <p className="text-[10px] font-semibold tracking-[0.1em] text-[var(--text-muted)] uppercase">
              Empresa
            </p>
            <p className="mt-1 text-[13px] font-medium">{prefill.organization ?? '—'}</p>
          </div>
          <div>
            <p className="text-[10px] font-semibold tracking-[0.1em] text-[var(--text-muted)] uppercase">
              Referencia del envío
            </p>
            <p className="mt-1 font-mono text-[13px] font-medium">{prefill.nextReference ?? '—'}</p>
          </div>
          <p className="text-[11px] text-[var(--text-muted)] sm:col-span-2">
            Ambos datos los completa el sistema automáticamente.
          </p>
        </div>

        <div className="grid gap-4 sm:grid-cols-2">
          <Field
            label="Su remito o referencia"
            hint="El número con el que usted identifica este envío."
            error={errors.client_reference}
            required
          >
            {(props) => (
              <Input
                {...props}
                value={data.client_reference}
                maxLength={60}
                onChange={(event) => setData('client_reference', event.target.value)}
                invalid={Boolean(errors.client_reference)}
              />
            )}
          </Field>

          <Field
            label="Llegada estimada al taller"
            hint="Ayuda al taller a planificar la carga."
            error={errors.expected_at}
            required
          >
            {(props) => (
              <Input
                {...props}
                type="date"
                value={data.expected_at}
                onChange={(event) => setData('expected_at', event.target.value)}
                invalid={Boolean(errors.expected_at)}
              />
            )}
          </Field>

          <Field label="Quién envía" error={errors.contact_name} required>
            {(props) => (
              <Input
                {...props}
                value={data.contact_name}
                maxLength={120}
                onChange={(event) => setData('contact_name', event.target.value)}
                invalid={Boolean(errors.contact_name)}
              />
            )}
          </Field>

          <Field label="Teléfono de contacto" error={errors.contact_phone}>
            {(props) => (
              <Input
                {...props}
                type="tel"
                value={data.contact_phone}
                maxLength={40}
                onChange={(event) => setData('contact_phone', event.target.value)}
                invalid={Boolean(errors.contact_phone)}
              />
            )}
          </Field>

          <Field label="Transporte" hint="Opcional. Empresa o vehículo." error={errors.carrier}>
            {(props) => (
              <Input
                {...props}
                value={data.carrier}
                maxLength={120}
                onChange={(event) => setData('carrier', event.target.value)}
              />
            )}
          </Field>

          <Field label="Origen" hint="Opcional. Base o yacimiento." error={errors.origin}>
            {(props) => (
              <Input
                {...props}
                value={data.origin}
                maxLength={160}
                onChange={(event) => setData('origin', event.target.value)}
              />
            )}
          </Field>
        </div>

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
