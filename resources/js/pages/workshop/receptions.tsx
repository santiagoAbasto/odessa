import { Deferred, Head, Link, router, useForm } from '@inertiajs/react';
import type { ColumnDef } from '@tanstack/react-table';
import {
  AlertTriangle,
  MessageSquareQuote,
  PackageCheck,
  PackagePlus,
  Play,
  Truck,
  Warehouse,
  Wrench,
  X,
  Zap,
} from 'lucide-react';
import { useState, useMemo, type FormEvent } from 'react';
import { DataTable, type ColumnMeta } from '@/components/tables/data-table';
import { FilterBar, type FilterDefinition } from '@/components/tables/filter-bar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { ConfirmButton } from '@/components/ui/confirm-button';
import { Dialog, DialogClose, DialogContent, DialogTrigger } from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { Field } from '@/components/ui/field';
import { Input, Select, Textarea } from '@/components/ui/input';
import { Panel } from '@/components/ui/panel';
import { Skeleton } from '@/components/ui/skeleton';
import { DirectReceptionDialog } from '@/components/workshop/direct-reception-dialog';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/cn';
import { formatDate, formatNumber } from '@/lib/format';
import type { Paginated, ReceptionRow, SelectOption, SortState, Tone } from '@/types';

interface StageCounts {
  pending: number;
  in_progress: number;
  rejected: number;
  awaiting_client: number;
  in_repair: number;
  ready: number;
  in_workshop: number;
  dispatched: number;
  overdue: number;
  urgent: number;
}

/*
  Las pestañas siguen el recorrido real del equipo:

    Pendiente → En proceso → Aprobada ──────────────────────────→ Despachada
                           ↘ Rechazada → A reparar ─┬─ SÍ → Reparada ↗
                                      (lo decide    │
                                       el cliente)  └─ NO → Despachada sin reparar

  "En taller" agrupa todo lo que no salió todavía, y es la vista con la que
  el taller arranca el día. "Listas" junta todo lo que puede despacharse
  —aprobadas, reparadas, y las que el cliente decidió no reparar—, que es lo
  único que importa a la hora de cargar el camión.
*/
const stageTabs = [
  { value: 'in_workshop', label: 'En taller' },
  { value: 'pending', label: 'Pendientes' },
  { value: 'in_progress', label: 'En proceso' },
  { value: 'rejected', label: 'Rechazadas' },
  // La cola que traba el taller sin que sea culpa del taller: conviene poder
  // verla de un vistazo para reclamarle al cliente.
  { value: 'awaiting_client', label: 'Esperando al cliente' },
  { value: 'in_repair', label: 'En reparación' },
  { value: 'ready', label: 'Listas para despachar' },
  { value: 'dispatched', label: 'Despachadas' },
  { value: 'all', label: 'Todas' },
];

export default function Receptions({
  receptions,
  filters,
  sort,
  counts,
  showOrganization,
  isClient,
  can,
  organizationOptions,
}: {
  receptions: Paginated<ReceptionRow>;
  filters: Record<string, string | undefined>;
  sort: SortState;
  counts?: StageCounts;
  /** Falso para un cliente: todo lo que ve ya es suyo. */
  showOrganization: boolean;
  /**
   * Cambia el formulario de autorización: el cliente contesta y listo, Odessa
   * tiene que declarar por qué vía le llegó y adjuntar el respaldo.
   */
  isClient: boolean;
  can: { create: boolean };
  organizationOptions: SelectOption[];
}) {
  const [ingresando, setIngresando] = useState(false);
  const filterDefinitions = useMemo<FilterDefinition[]>(
    () => [
      { key: 'search', label: 'Pieza', type: 'text' },
      { key: 'report_number', label: 'Número de FMR', type: 'text' },
      {
        key: 'urgent',
        label: 'Sólo urgentes',
        type: 'select',
        options: [{ value: '1', label: 'Sí' }],
      },
      {
        key: 'overdue',
        label: 'Sólo vencidas',
        type: 'select',
        options: [{ value: '1', label: 'Sí' }],
      },
    ],
    [],
  );

  const columns = useMemo<ColumnDef<ReceptionRow, unknown>[]>(
    () => [
      {
        id: 'urgent',
        header: '!',
        meta: { sortKey: 'urgent' } satisfies ColumnMeta,
        cell: ({ row }) =>
          row.original.isUrgent ? (
            <span title="Urgente" className="inline-flex text-[var(--tone-danger-fg)]">
              <Zap className="size-4" aria-hidden />
              <span className="sr-only">Urgente</span>
            </span>
          ) : null,
      },
      {
        id: 'asset',
        header: 'Pieza',
        meta: { locked: true } satisfies ColumnMeta,
        cell: ({ row }) => (
          <div className="min-w-0">
            <Link
              href={`/activos/${row.original.asset.id}`}
              onClick={(event) => event.stopPropagation()}
              className="block truncate font-medium text-[var(--text-accent)] hover:underline"
            >
              {row.original.asset.name}
            </Link>
            <p className="mt-0.5 truncate font-mono text-[11px] text-[var(--text-muted)]">
              {row.original.asset.serialNumber ?? row.original.asset.code ?? '—'}
            </p>
          </div>
        ),
      },
      /*
        En el taller conviven equipos de todas las empresas. Sin esta columna
        no hay forma de saber de quién es lo que se está por liberar.
      */
      ...(showOrganization
        ? ([
            {
              id: 'organization',
              header: 'Cliente',
              cell: ({ row }) => (
                <span className="block max-w-[170px] truncate text-[var(--text-secondary)]">
                  {row.original.asset.organization ?? '—'}
                </span>
              ),
            },
          ] as ColumnDef<ReceptionRow, unknown>[])
        : []),
      {
        id: 'report',
        header: 'FMR',
        cell: ({ row }) =>
          row.original.reportId ? (
            <Link
              href={`/informes/${row.original.reportId}`}
              onClick={(event) => event.stopPropagation()}
              className="font-mono text-[11.5px] text-[var(--text-accent)] hover:underline"
            >
              {row.original.reportNumber}
            </Link>
          ) : (
            <span className="text-[var(--text-muted)]">—</span>
          ),
      },
      {
        id: 'receivedAt',
        header: 'Ingreso',
        meta: { sortKey: 'received_at' } satisfies ColumnMeta,
        cell: ({ row }) => <span className="tabular">{formatDate(row.original.receivedAt)}</span>,
      },
      {
        id: 'promisedAt',
        header: 'Comprometido',
        meta: { sortKey: 'promised_at' } satisfies ColumnMeta,
        cell: ({ row }) => (
          <div>
            <span className={cn('tabular', row.original.isOverdue && 'text-[var(--tone-danger-fg)]')}>
              {formatDate(row.original.promisedAt)}
            </span>
            {row.original.isOverdue && (
              <span className="mt-0.5 flex items-center gap-1 text-[10.5px] text-[var(--tone-danger-fg)]">
                <AlertTriangle className="size-3" aria-hidden />
                Fuera de plazo
              </span>
            )}
          </div>
        ),
      },
      {
        id: 'assetStatus',
        header: 'Aptitud',
        cell: ({ row }) => (
          <Badge tone={row.original.asset.statusTone}>{row.original.asset.statusLabel}</Badge>
        ),
      },
      {
        id: 'stage',
        header: 'Etapa',
        cell: ({ row }) => (
          <div className="min-w-0">
            <Badge tone={row.original.stageTone}>{row.original.stageLabel}</Badge>
            {/* El motivo del rechazo es lo que explica todo lo que sigue. */}
            {row.original.outcomeNotes && row.original.outcome === 'rejected' && (
              <p className="mt-1 max-w-[200px] truncate text-[10.5px] text-[var(--tone-danger-fg)]"
                 title={row.original.outcomeNotes}>
                {row.original.outcomeNotes}
              </p>
            )}
          </div>
        ),
      },
      {
        id: 'actions',
        header: 'Acciones',
        meta: { align: 'right', locked: true } satisfies ColumnMeta,
        cell: ({ row }) => (
          // Las acciones quedan a la vista, no escondidas tras un menú:
          // liberar y despachar son el trabajo diario de esta pantalla.
          <div
            className="flex justify-end gap-1.5"
            onClick={(event) => event.stopPropagation()}
          >
            {row.original.can.start && (
              <ConfirmButton
                variant="secondary"
                size="sm"
                title="Empezar el trabajo"
                description="Marca que el taller tomó el equipo. Sale de la cola de pendientes y pasa a En proceso."
                confirmLabel="Empezar"
                onConfirm={() =>
                  router.post(`/taller/recepciones/${row.original.id}/empezar`, {}, { preserveScroll: true })
                }
              >
                <Play aria-hidden />
                Empezar
              </ConfirmButton>
            )}

            {/*
              El dictamen es una decisión con dos salidas, así que se ofrecen
              las dos: aprobar deja la pieza lista para salir; rechazar abre el
              circuito de reparación y exige motivo.
            */}
            {row.original.can.resolve && (
              <>
                <ConfirmButton
                  variant="secondary"
                  size="sm"
                  title="Aprobar el trabajo"
                  description="El trabajo se dio por bueno y la pieza queda lista para despachar."
                  confirmLabel="Aprobar"
                  onConfirm={() =>
                    router.post(
                      `/taller/recepciones/${row.original.id}/dictamen`,
                      { outcome: 'approved' },
                      { preserveScroll: true },
                    )
                  }
                >
                  <PackageCheck aria-hidden />
                  Aprobar
                </ConfirmButton>

                <RejectButton receptionId={row.original.id} />
              </>
            )}

            {/*
              La reparación se le cobra al cliente, así que la decide el
              cliente. El taller propone; recién con la respuesta registrada
              puede empezar a reparar —o devolver el equipo como está.
            */}
            {row.original.can.requestRepair && <RequestRepairButton receptionId={row.original.id} />}

            {row.original.can.decideRepair && (
              <RepairDecisionDialog
                receptionId={row.original.id}
                proposal={row.original.repairRequestNotes}
                isClient={isClient}
              />
            )}

            {row.original.can.sendToRepair && (
              <ConfirmButton
                variant="secondary"
                size="sm"
                title="Empezar la reparación"
                description="El cliente ya autorizó. La pieza pasa a repararse y hasta que la reparación termine no puede despacharse."
                confirmLabel="Empezar reparación"
                onConfirm={() =>
                  router.post(`/taller/recepciones/${row.original.id}/reparar`, {}, { preserveScroll: true })
                }
              >
                <Wrench aria-hidden />
                A reparar
              </ConfirmButton>
            )}

            {row.original.can.markRepaired && (
              <ConfirmButton
                variant="secondary"
                size="sm"
                title="Terminar la reparación"
                description="La reparación se dio por terminada y la pieza queda lista para despachar."
                confirmLabel="Marcar reparada"
                onConfirm={() =>
                  router.post(`/taller/recepciones/${row.original.id}/reparada`, {}, { preserveScroll: true })
                }
              >
                <PackageCheck aria-hidden />
                Reparada
              </ConfirmButton>
            )}

            {row.original.can.dispatch && (
              <ConfirmButton
                variant="primary"
                size="sm"
                title="Despachar pieza"
                description="La pieza sale del taller y vuelve al cliente. El hito queda registrado con su usuario."
                confirmLabel="Despachar"
                onConfirm={() =>
                  router.post(`/taller/recepciones/${row.original.id}/despachar`, {}, { preserveScroll: true })
                }
              >
                <Truck aria-hidden />
                Despachar
              </ConfirmButton>
            )}
          </div>
        ),
      },
    ],
    [showOrganization],
  );

  const activeStage = filters.stage ?? 'in_workshop';

  return (
    <AppLayout
      title="Recepción de equipos"
      description="Recorrido del equipo en el taller: ingreso, dictamen, reparación si hace falta, y despacho."
      breadcrumbs={[{ label: 'Taller' }, { label: 'Recepción de equipos' }]}
      actions={
        /*
          Ingreso directo. El circuito normal es que el cliente declare el
          envío, pero llegan equipos sin nada cargado y el taller tiene que
          poder registrarlos igual.
        */
        can.create && (
          <Dialog open={ingresando} onOpenChange={setIngresando}>
            <DialogTrigger asChild>
              <Button variant="primary" size="sm">
                <PackagePlus aria-hidden />
                Ingresar equipo
              </Button>
            </DialogTrigger>
            <DirectReceptionDialog
              organizations={organizationOptions}
              onDone={() => setIngresando(false)}
            />
          </Dialog>
        )
      }
    >
      <Head title="Recepción de equipos" />

      <Deferred data="counts" fallback={<Skeleton className="mb-5 h-[88px]" />}>
        <StageSummary counts={counts} />
      </Deferred>

      {/* Etapas del pipeline como pestañas: en el sistema anterior eran dos
          pantallas distintas con querystring, difíciles de comparar. */}
      <div
        role="tablist"
        aria-label="Etapa del pipeline"
        className="mb-4 flex flex-wrap gap-0.5 rounded-sm border border-[var(--border-default)] bg-[var(--surface-raised)] p-0.5"
      >
        {stageTabs.map((tab) => (
          <Link
            key={tab.value}
            href="/taller/recepciones"
            data={{ stage: tab.value }}
            preserveScroll
            role="tab"
            aria-selected={activeStage === tab.value}
            className={cn(
              'rounded-xs px-3 py-1.5 text-[12.5px] transition-colors',
              activeStage === tab.value
                ? 'bg-[var(--accent)] font-medium text-[var(--text-inverse)]'
                : 'text-[var(--text-secondary)] hover:bg-[var(--surface-hover)]',
            )}
          >
            {tab.label}
          </Link>
        ))}
      </div>

      <Panel>
        <DataTable
          columns={columns}
          page={receptions}
          sort={sort}
          baseUrl="/taller/recepciones"
          query={filters}
          storageKey="receptions"
          toolbar={
            <FilterBar
              baseUrl="/taller/recepciones"
              filters={filters}
              definitions={filterDefinitions}
              searchPlaceholder="Pieza, serie o código…"
            />
          }
          emptyState={
            <EmptyState
              icon={Warehouse}
              title="Sin recepciones"
              description="No hay piezas en esta etapa con los filtros aplicados."
              action={
                <Button asChild variant="secondary" size="sm">
                  <Link href="/taller/recepciones">Ver todas</Link>
                </Button>
              }
            />
          }
        />
      </Panel>
    </AppLayout>
  );
}

function StageSummary({ counts }: { counts?: StageCounts }) {
  if (!counts) return null;

  const items = [
    { label: 'Pendientes', value: counts.pending, href: '?stage=pending' },
    { label: 'En proceso', value: counts.in_progress, href: '?stage=in_progress' },
    { label: 'Fuera de plazo', value: counts.overdue, href: '?stage=pending&overdue=1', tone: 'danger' as Tone },
    { label: 'Urgentes', value: counts.urgent, href: '?stage=in_workshop&urgent=1', tone: 'warning' as Tone },
    { label: 'Rechazadas', value: counts.rejected, href: '?stage=rejected', tone: 'danger' as Tone },
    {
      label: 'Esperando al cliente',
      value: counts.awaiting_client,
      href: '?stage=awaiting_client',
      tone: 'warning' as Tone,
    },
    { label: 'En reparación', value: counts.in_repair, href: '?stage=in_repair', tone: 'warning' as Tone },
    { label: 'Listas para despachar', value: counts.ready, href: '?stage=ready' },
    { label: 'Despachadas', value: counts.dispatched, href: '?stage=dispatched' },
  ];

  return (
    <div className="mb-5 grid gap-px overflow-hidden rounded-sm border border-[var(--border-subtle)] bg-[var(--border-subtle)] sm:grid-cols-3 lg:grid-cols-4">
      {items.map((item) => (
        <Link
          key={item.label}
          href={`/taller/recepciones${item.href}`}
          className="bg-[var(--surface-raised)] p-3.5 transition-colors hover:bg-[var(--surface-hover)]"
        >
          <p className="text-[11px] font-medium text-[var(--text-secondary)]">{item.label}</p>
          <p
            className={cn(
              'mt-1.5 text-[24px] leading-none font-semibold tabular',
              item.tone === 'danger' && item.value > 0 && 'text-[var(--tone-danger-fg)]',
              item.tone === 'warning' && item.value > 0 && 'text-[var(--tone-warning-fg)]',
            )}
          >
            {formatNumber(item.value)}
          </p>
        </Link>
      ))}
    </div>
  );
}

/**
 * Rechazar exige motivo, así que no puede ser un botón de confirmación
 * simple: lo que se escriba acá es lo que después justifica la reparación
 * y lo que ve el cliente en la trazabilidad.
 */
function RejectButton({ receptionId }: { receptionId: string }) {
  const [open, setOpen] = useState(false);
  const { data, setData, post, processing, errors, reset } = useForm({
    outcome: 'rejected',
    outcome_notes: '',
  });

  const submit = (event: FormEvent) => {
    event.preventDefault();
    post(`/taller/recepciones/${receptionId}/dictamen`, {
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
          <X aria-hidden />
          Rechazar
        </Button>
      </DialogTrigger>

      <DialogContent
        title="Rechazar el trabajo"
        description="Indique por qué no se aprueba. Después podrá mandarla a reparación."
        footer={
          <>
            <DialogClose asChild>
              <Button variant="secondary" size="sm">
                Cancelar
              </Button>
            </DialogClose>
            <Button type="submit" form="form-rechazo" variant="danger" size="sm" loading={processing}>
              Rechazar
            </Button>
          </>
        }
      >
        <form id="form-rechazo" onSubmit={submit} noValidate>
          <Field label="Motivo del rechazo" error={errors.outcome_notes} required>
            {(props) => (
              <Textarea
                {...props}
                value={data.outcome_notes}
                maxLength={500}
                onChange={(event) => setData('outcome_notes', event.target.value)}
                invalid={Boolean(errors.outcome_notes)}
              />
            )}
          </Field>
        </form>
      </DialogContent>
    </Dialog>
  );
}

/**
 * Consulta al cliente si autoriza la reparación.
 *
 * Lo que se escriba acá es lo que el cliente lee para decidir, así que
 * conviene que diga qué se va a hacer y cuánto cuesta. Es texto libre a
 * propósito: el presupuesto lo arma el taller caso por caso.
 */
function RequestRepairButton({ receptionId }: { receptionId: string }) {
  const [open, setOpen] = useState(false);
  const { data, setData, post, processing, errors, reset } = useForm({ notes: '' });

  const submit = (event: FormEvent) => {
    event.preventDefault();
    post(`/taller/recepciones/${receptionId}/consultar-reparacion`, {
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
          <MessageSquareQuote aria-hidden />
          Consultar al cliente
        </Button>
      </DialogTrigger>

      <DialogContent
        title="Consultar la reparación al cliente"
        description="La pieza queda esperando la respuesta. Hasta que el cliente conteste no se puede reparar ni despachar."
        footer={
          <>
            <DialogClose asChild>
              <Button variant="secondary" size="sm">
                Cancelar
              </Button>
            </DialogClose>
            <Button type="submit" form="form-consulta-reparacion" variant="primary" size="sm" loading={processing}>
              Enviar consulta
            </Button>
          </>
        }
      >
        <form id="form-consulta-reparacion" onSubmit={submit} noValidate>
          <Field
            label="Qué se propone reparar"
            hint="Es lo que el cliente lee para decidir. Conviene detallar el trabajo y el costo estimado."
            error={errors.notes}
          >
            {(props) => (
              <Textarea
                {...props}
                value={data.notes}
                maxLength={2000}
                rows={4}
                onChange={(event) => setData('notes', event.target.value)}
                invalid={Boolean(errors.notes)}
              />
            )}
          </Field>
        </form>
      </DialogContent>
    </Dialog>
  );
}

/**
 * Respuesta del cliente sobre la reparación.
 *
 * El formulario cambia según quién lo abre. Si lo contesta el propio cliente,
 * el registro mismo es la constancia y alcanza con decir sí o no. Si lo carga
 * Odessa —porque la respuesta llegó por teléfono o correo— hay que decir por
 * qué vía y adjuntar el respaldo: la reparación después se cobra, y esa
 * transcripción tiene que poder mostrarse.
 *
 * El canal no se ofrece como opción libre: el servidor rechaza que Odessa
 * declare "desde el panel", porque si hubiera venido del panel lo habría
 * cargado el cliente.
 */
function RepairDecisionDialog({
  receptionId,
  proposal,
  isClient,
}: {
  receptionId: string;
  proposal: string | null;
  isClient: boolean;
}) {
  const [open, setOpen] = useState(false);
  const { data, setData, post, processing, errors, reset } = useForm<{
    decision: 'approved' | 'declined';
    channel: string;
    notes: string;
    evidence: File | null;
  }>({
    decision: 'approved',
    channel: isClient ? 'panel' : 'email',
    notes: '',
    evidence: null,
  });

  const submit = (event: FormEvent) => {
    event.preventDefault();
    post(`/taller/recepciones/${receptionId}/autorizacion`, {
      preserveScroll: true,
      forceFormData: true,
      onSuccess: () => {
        reset();
        setOpen(false);
      },
    });
  };

  const rechaza = data.decision === 'declined';

  return (
    <Dialog open={open} onOpenChange={setOpen}>
      <DialogTrigger asChild>
        <Button variant="primary" size="sm">
          <MessageSquareQuote aria-hidden />
          {isClient ? 'Responder' : 'Registrar respuesta'}
        </Button>
      </DialogTrigger>

      <DialogContent
        title={isClient ? 'Autorización de reparación' : 'Registrar la respuesta del cliente'}
        description={
          isClient
            ? 'El equipo no pasó la inspección. Indique si autoriza la reparación.'
            : 'Cargue lo que el cliente contestó y adjunte el respaldo.'
        }
        footer={
          <>
            <DialogClose asChild>
              <Button variant="secondary" size="sm">
                Cancelar
              </Button>
            </DialogClose>
            <Button
              type="submit"
              form="form-decision-reparacion"
              variant={rechaza ? 'danger' : 'primary'}
              size="sm"
              loading={processing}
            >
              {rechaza ? 'No autorizar' : 'Autorizar'}
            </Button>
          </>
        }
      >
        <form id="form-decision-reparacion" onSubmit={submit} noValidate className="flex flex-col gap-4">
          {proposal && (
            <div className="rounded-xs border border-[var(--border-default)] bg-[var(--surface-sunken)] p-3">
              <p className="text-[11px] font-medium text-[var(--text-secondary)]">Reparación propuesta</p>
              <p className="mt-1 text-[13px] whitespace-pre-line text-[var(--text-primary)]">{proposal}</p>
            </div>
          )}

          <Field label="Respuesta" error={errors.decision} required>
            {(props) => (
              <Select
                {...props}
                value={data.decision}
                onChange={(event) => setData('decision', event.target.value as 'approved' | 'declined')}
                invalid={Boolean(errors.decision)}
              >
                <option value="approved">Autoriza la reparación</option>
                <option value="declined">No autoriza: devolver sin reparar</option>
              </Select>
            )}
          </Field>

          {/* Sólo Odessa declara el canal: el cliente contesta desde el panel
              por definición, así que no tiene nada que elegir. */}
          {!isClient && (
            <Field label="Por qué vía llegó la respuesta" error={errors.channel} required>
              {(props) => (
                <Select
                  {...props}
                  value={data.channel}
                  onChange={(event) => setData('channel', event.target.value)}
                  invalid={Boolean(errors.channel)}
                >
                  <option value="email">Correo electrónico</option>
                  <option value="phone">Teléfono</option>
                  <option value="whatsapp">WhatsApp</option>
                  <option value="in_person">En persona</option>
                </Select>
              )}
            </Field>
          )}

          <Field
            label={rechaza ? 'Motivo' : 'Observaciones'}
            hint={
              rechaza
                ? 'El equipo va a volver sin certificar: dejar asentado por qué.'
                : undefined
            }
            error={errors.notes}
            required={rechaza}
          >
            {(props) => (
              <Textarea
                {...props}
                value={data.notes}
                maxLength={2000}
                rows={3}
                onChange={(event) => setData('notes', event.target.value)}
                invalid={Boolean(errors.notes)}
              />
            )}
          </Field>

          {!isClient && (
            <Field
              label="Respaldo de la respuesta"
              hint="Captura del correo o del mensaje. Es lo que justifica el cobro de la reparación si después se discute."
              error={errors.evidence}
              required
            >
              {(props) => (
                <Input
                  {...props}
                  type="file"
                  accept="image/jpeg,image/png,image/webp,application/pdf"
                  onChange={(event) => setData('evidence', event.target.files?.[0] ?? null)}
                  invalid={Boolean(errors.evidence)}
                />
              )}
            </Field>
          )}
        </form>
      </DialogContent>
    </Dialog>
  );
}
