import { Deferred, Head, Link, router, useForm } from '@inertiajs/react';
import { Check, Package, PackagePlus, Send, Trash2, Truck, X, Zap } from 'lucide-react';
import { useState, type FormEvent } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { ConfirmButton } from '@/components/ui/confirm-button';
import { Dialog, DialogClose, DialogContent, DialogTrigger } from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { Field } from '@/components/ui/field';
import { Input, Select, Textarea } from '@/components/ui/input';
import { Panel, PanelHeader } from '@/components/ui/panel';
import { Skeleton } from '@/components/ui/skeleton';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/cn';
import { formatDate, formatDateTime } from '@/lib/format';
import type { Tone } from '@/types';

interface ShipmentDetail {
  id: string;
  reference: string;
  status: string;
  statusLabel: string;
  statusTone: Tone;
  expectedAt: string | null;
  notes: string | null;
  organization: string | null;
  createdBy: string | null;
  createdAt: string | null;
  submittedAt: string | null;
  reviewedBy: string | null;
  reviewedAt: string | null;
  reviewNotes: string | null;
}

interface ShipmentItem {
  id: number;
  name: string;
  serial: string | null;
  isRegistered: boolean;
  assetId: string | null;
  code: string | null;
  isUrgent: boolean;
  notes: string | null;
  receptionId: string | null;
}

interface AvailableAsset {
  id: string;
  label: string;
}

export default function ShipmentShow({
  shipment,
  items,
  can,
  availableAssets,
}: {
  shipment: ShipmentDetail;
  items: ShipmentItem[];
  can: { update: boolean; submit: boolean; review: boolean; delete: boolean };
  availableAssets?: AvailableAsset[];
}) {
  return (
    <AppLayout
      title={`Envío ${shipment.reference}`}
      description={shipment.organization ?? undefined}
      breadcrumbs={[{ label: 'Envíos al taller', href: '/envios' }, { label: shipment.reference }]}
      actions={
        <div className="flex items-center gap-2">
          {can.submit && (
            <ConfirmButton
              variant="primary"
              size="sm"
              title="Remitir el envío al taller"
              description="El taller le dará recepción cuando los equipos lleguen, confirmando lo declarado. Después de remitirlo ya no podrá modificar los equipos."
              confirmLabel="Remitir al taller"
              onConfirm={() => router.post(`/envios/${shipment.id}/enviar`, {}, { preserveScroll: true })}
            >
              <Send aria-hidden />
              Remitir al taller
            </ConfirmButton>
          )}

          {can.review && <ReviewDialog shipmentId={shipment.id} itemsCount={items.length} />}
        </div>
      }
    >
      <Head title={`Envío ${shipment.reference}`} />

      {/* ================= Estado ================= */}
      <Panel className="mb-5">
        <div className="grid gap-px bg-[var(--border-subtle)] sm:grid-cols-2 lg:grid-cols-4">
          <Metric label="Estado">
            <Badge tone={shipment.statusTone} className="px-2.5 py-1.5 text-[12.5px]">
              {shipment.statusLabel}
            </Badge>
          </Metric>
          <Metric label="Equipos declarados">
            <p className="text-[20px] leading-none font-semibold tabular">{items.length}</p>
          </Metric>
          <Metric label="Llegada estimada">
            <p className="text-[16px] leading-none font-semibold tabular">
              {formatDate(shipment.expectedAt)}
            </p>
          </Metric>
          <Metric label="Declarado por">
            <p className="text-[13px]">{shipment.createdBy ?? '—'}</p>
            <p className="mt-1 text-[11px] text-[var(--text-muted)]">
              {formatDateTime(shipment.createdAt)}
            </p>
          </Metric>
        </div>

        {shipment.notes && (
          <p className="border-t border-[var(--border-subtle)] px-4 py-3 text-[12.5px] text-[var(--text-secondary)]">
            {shipment.notes}
          </p>
        )}

        {shipment.reviewedAt && (
          <div
            className={cn(
              'border-t px-4 py-3',
              shipment.status === 'authorized'
                ? 'border-[var(--tone-success-fg)]/25 bg-[var(--tone-success-bg)]'
                : 'border-[var(--tone-danger-fg)]/25 bg-[var(--tone-danger-bg)]',
            )}
          >
            <p
              className={cn(
                'text-[12.5px] font-medium',
                shipment.status === 'authorized'
                  ? 'text-[var(--tone-success-fg)]'
                  : 'text-[var(--tone-danger-fg)]',
              )}
            >
              {shipment.status === 'authorized' ? 'Recibido' : 'Rechazado'} por{' '}
              {shipment.reviewedBy} · {formatDateTime(shipment.reviewedAt)}
            </p>
            {shipment.reviewNotes && (
              <p className="mt-1 text-[12px] text-[var(--text-secondary)]">{shipment.reviewNotes}</p>
            )}
          </div>
        )}
      </Panel>

      {/* ================= Equipos ================= */}
      <Panel>
        <PanelHeader
          title="Equipos declarados"
          description={
            can.review
              ? 'Al dar recepción se creará una recepción por cada equipo. Los no registrados se darán de alta.'
              : 'Los equipos que se enviarán al taller.'
          }
          actions={
            can.update ? (
              <Deferred data="availableAssets" fallback={<Skeleton className="h-8 w-28" />}>
                <AddItemDialog shipmentId={shipment.id} assets={availableAssets ?? []} />
              </Deferred>
            ) : undefined
          }
        />

        {items.length === 0 ? (
          <EmptyState
            icon={Package}
            title="Sin equipos"
            description={
              can.update
                ? 'Agregue los equipos que va a enviar al taller.'
                : 'Este envío todavía no tiene equipos declarados.'
            }
          />
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full min-w-[640px] text-[12.5px]">
              <thead>
                <tr>
                  {['Equipo', 'Serie', 'Estado', 'Nota', ''].map((header) => (
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
                {items.map((item) => (
                  <tr
                    key={item.id}
                    className="border-b border-[var(--border-subtle)] last:border-b-0 hover:bg-[var(--surface-hover)]"
                  >
                    <td className="px-3 py-2.5">
                      <div className="flex items-center gap-2">
                        {item.isUrgent && (
                          <span title="Urgente" className="text-[var(--tone-danger-fg)]">
                            <Zap className="size-3.5" aria-hidden />
                            <span className="sr-only">Urgente</span>
                          </span>
                        )}
                        {item.assetId ? (
                          <Link
                            href={`/activos/${item.assetId}`}
                            className="font-medium text-[var(--text-accent)] hover:underline"
                          >
                            {item.name}
                          </Link>
                        ) : (
                          <span className="font-medium">{item.name}</span>
                        )}
                      </div>
                    </td>
                    <td className="px-3 py-2.5 font-mono text-[11.5px]">{item.serial ?? '—'}</td>
                    <td className="px-3 py-2.5">
                      {item.isRegistered ? (
                        <Badge tone="success">Registrado</Badge>
                      ) : (
                        <Badge tone="warning">Alta pendiente</Badge>
                      )}
                    </td>
                    <td className="px-3 py-2.5 text-[11.5px] text-[var(--text-secondary)]">
                      {item.notes ?? '—'}
                    </td>
                    <td className="px-3 py-2.5 text-right">
                      {item.receptionId ? (
                        <Link
                          href="/taller/recepciones"
                          className="text-[11.5px] text-[var(--text-accent)] hover:underline"
                        >
                          Ver en taller
                        </Link>
                      ) : (
                        can.update && (
                          <Button
                            variant="ghost"
                            size="icon"
                            aria-label={`Quitar ${item.name}`}
                            onClick={() =>
                              router.delete(`/envios/${shipment.id}/equipos/${item.id}`, {
                                preserveScroll: true,
                              })
                            }
                          >
                            <Trash2 aria-hidden />
                          </Button>
                        )
                      )}
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

function Metric({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="bg-[var(--surface-raised)] p-4">
      <p className="text-[11px] font-medium text-[var(--text-secondary)]">{label}</p>
      <div className="mt-2">{children}</div>
    </div>
  );
}

function AddItemDialog({ shipmentId, assets }: { shipmentId: string; assets: AvailableAsset[] }) {
  const [open, setOpen] = useState(false);

  const { data, setData, post, processing, errors, reset } = useForm({
    asset_public_id: '',
    declared_description: '',
    declared_serial: '',
    is_urgent: false as boolean,
    notes: '',
  });

  const submit = (event: FormEvent) => {
    event.preventDefault();
    post(`/envios/${shipmentId}/equipos`, {
      preserveScroll: true,
      onSuccess: () => {
        reset();
        setOpen(false);
      },
    });
  };

  return (
    <Dialog open={open} onOpenChange={setOpen}>
      <DialogTrigger asChild>
        <Button variant="secondary" size="sm">
          <PackagePlus aria-hidden />
          Agregar equipo
        </Button>
      </DialogTrigger>

      <DialogContent
        title="Agregar equipo al envío"
        description="Elija una pieza ya registrada o describa una que todavía no lo está."
        footer={
          <>
            <DialogClose asChild>
              <Button variant="secondary" size="sm">
                Cancelar
              </Button>
            </DialogClose>
            <Button type="submit" form="form-item" variant="primary" size="sm" loading={processing}>
              Agregar
            </Button>
          </>
        }
      >
        <form id="form-item" onSubmit={submit} className="flex flex-col gap-4" noValidate>
          <Field
            label="Pieza registrada"
            hint="Si el equipo ya está en el sistema, elíjalo aquí."
            error={errors.asset_public_id}
          >
            {(props) => (
              <Select
                {...props}
                value={data.asset_public_id}
                onChange={(event) => setData('asset_public_id', event.target.value)}
              >
                <option value="">— No está registrado —</option>
                {assets.map((asset) => (
                  <option key={asset.id} value={asset.id}>
                    {asset.label}
                  </option>
                ))}
              </Select>
            )}
          </Field>

          {!data.asset_public_id && (
            <>
              <Field
                label="Descripción del equipo"
                hint="El taller lo dará de alta al dar recepción al envío."
                error={errors.declared_description}
                required
              >
                {(props) => (
                  <Input
                    {...props}
                    value={data.declared_description}
                    maxLength={180}
                    onChange={(event) => setData('declared_description', event.target.value)}
                    invalid={Boolean(errors.declared_description)}
                  />
                )}
              </Field>

              <Field label="Número de serie" error={errors.declared_serial}>
                {(props) => (
                  <Input
                    {...props}
                    value={data.declared_serial}
                    maxLength={120}
                    onChange={(event) => setData('declared_serial', event.target.value)}
                  />
                )}
              </Field>
            </>
          )}

          <label className="flex items-center gap-2 text-[12.5px]">
            <input
              type="checkbox"
              checked={data.is_urgent}
              onChange={(event) => setData('is_urgent', event.target.checked)}
              className="size-3.5 rounded-xs accent-[var(--accent)]"
            />
            Marcar como urgente
          </label>

          <Field label="Nota" hint="Opcional." error={errors.notes}>
            {(props) => (
              <Input
                {...props}
                value={data.notes}
                maxLength={200}
                onChange={(event) => setData('notes', event.target.value)}
              />
            )}
          </Field>
        </form>
      </DialogContent>
    </Dialog>
  );
}

function ReviewDialog({ shipmentId, itemsCount }: { shipmentId: string; itemsCount: number }) {
  const [open, setOpen] = useState(false);
  const [decision, setDecision] = useState<'authorize' | 'reject'>('authorize');

  const { data, setData, post, processing, errors, reset } = useForm({
    decision: 'authorize',
    review_notes: '',
    promised_at: '',
  });

  const submit = (event: FormEvent) => {
    event.preventDefault();
    post(`/envios/${shipmentId}/revisar`, {
      preserveScroll: true,
      onSuccess: () => {
        reset();
        setOpen(false);
      },
    });
  };

  const choose = (value: 'authorize' | 'reject') => {
    setDecision(value);
    setData('decision', value);
  };

  return (
    <Dialog open={open} onOpenChange={setOpen}>
      <DialogTrigger asChild>
        <Button variant="primary" size="sm">
          <Truck aria-hidden />
          Dar recepción
        </Button>
      </DialogTrigger>

      <DialogContent
        title="Dar recepción al envío"
        description={`${itemsCount} ${itemsCount === 1 ? 'equipo declarado' : 'equipos declarados'}.`}
        footer={
          <>
            <DialogClose asChild>
              <Button variant="secondary" size="sm">
                Cancelar
              </Button>
            </DialogClose>
            <Button
              type="submit"
              form="form-revision"
              variant={decision === 'authorize' ? 'primary' : 'danger'}
              size="sm"
              loading={processing}
            >
              {decision === 'authorize' ? 'Dar recepción' : 'Rechazar'}
            </Button>
          </>
        }
      >
        <form id="form-revision" onSubmit={submit} className="flex flex-col gap-4" noValidate>
          <div className="grid grid-cols-2 gap-2">
            <button
              type="button"
              onClick={() => choose('authorize')}
              className={cn(
                'flex items-center gap-2 rounded-sm border px-3 py-2.5 text-left text-[12.5px] transition-colors',
                decision === 'authorize'
                  ? 'border-[var(--tone-success-fg)] bg-[var(--tone-success-bg)] text-[var(--tone-success-fg)]'
                  : 'border-[var(--border-default)] hover:bg-[var(--surface-hover)]',
              )}
              aria-pressed={decision === 'authorize'}
            >
              <Check className="size-4 shrink-0" aria-hidden />
              Recibido: confirmar lo declarado
            </button>

            <button
              type="button"
              onClick={() => choose('reject')}
              className={cn(
                'flex items-center gap-2 rounded-sm border px-3 py-2.5 text-left text-[12.5px] transition-colors',
                decision === 'reject'
                  ? 'border-[var(--tone-danger-fg)] bg-[var(--tone-danger-bg)] text-[var(--tone-danger-fg)]'
                  : 'border-[var(--border-default)] hover:bg-[var(--surface-hover)]',
              )}
              aria-pressed={decision === 'reject'}
            >
              <X className="size-4 shrink-0" aria-hidden />
              Rechazar
            </button>
          </div>

          {decision === 'authorize' ? (
            <>
              <p className="rounded-xs bg-[var(--surface-sunken)] px-3 py-2 text-[12px] text-[var(--text-secondary)]">
                Al dar recepción se creará una recepción por cada equipo, que queda en la cola
                de Pendientes del taller. Los equipos declarados sin registrar se darán de alta en el inventario.
              </p>

              <Field
                label="Fecha comprometida de entrega"
                hint="Opcional. Se aplica a todas las recepciones que se creen."
                error={errors.promised_at}
              >
                {(props) => (
                  <Input
                    {...props}
                    type="date"
                    value={data.promised_at}
                    onChange={(event) => setData('promised_at', event.target.value)}
                  />
                )}
              </Field>
            </>
          ) : (
            <p className="rounded-xs bg-[var(--tone-danger-bg)] px-3 py-2 text-[12px] text-[var(--tone-danger-fg)]">
              El cliente verá el motivo del rechazo. No se creará ninguna recepción.
            </p>
          )}

          <Field
            label={decision === 'reject' ? 'Motivo del rechazo' : 'Observaciones'}
            hint={decision === 'reject' ? undefined : 'Opcional.'}
            error={errors.review_notes}
            required={decision === 'reject'}
          >
            {(props) => (
              <Textarea
                {...props}
                value={data.review_notes}
                maxLength={500}
                onChange={(event) => setData('review_notes', event.target.value)}
                invalid={Boolean(errors.review_notes)}
              />
            )}
          </Field>
        </form>
      </DialogContent>
    </Dialog>
  );
}
