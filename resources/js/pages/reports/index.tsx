import { Head, Link, useForm } from '@inertiajs/react';
import type { ColumnDef } from '@tanstack/react-table';
import { FilePlus2, FileSearch, Plus } from 'lucide-react';
import { useMemo, useState, type FormEvent } from 'react';
import { DataTable, type ColumnMeta } from '@/components/tables/data-table';
import { FilterBar, type FilterDefinition } from '@/components/tables/filter-bar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogClose, DialogContent, DialogTrigger } from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { Field } from '@/components/ui/field';
import { Input, Select, Textarea } from '@/components/ui/input';
import { Panel } from '@/components/ui/panel';
import AppLayout from '@/layouts/app-layout';
import { formatDate } from '@/lib/format';
import type { Paginated, ReportRow, SelectOption, SortState } from '@/types';

interface ReportsIndexProps {
  reports: Paginated<ReportRow>;
  filters: Record<string, string | undefined>;
  sort: SortState;
  statusOptions: SelectOption[];
  /** Falso para un cliente: todo lo que ve ya es suyo. */
  showOrganization: boolean;
  /** Vacío para un cliente, por el mismo motivo. */
  organizationOptions: SelectOption[];
  serviceOptions: SelectOption[];
  can: { create: boolean };
}

export default function ReportsIndex({
  reports,
  filters,
  sort,
  statusOptions,
  showOrganization,
  organizationOptions,
  serviceOptions,
  can,
}: ReportsIndexProps) {
  const [creating, setCreating] = useState(false);
  const filterDefinitions = useMemo<FilterDefinition[]>(
    () => [
      { key: 'search', label: 'Número de informe', type: 'text' },
      { key: 'status', label: 'Estado', type: 'select', options: statusOptions },
      // Sólo para el personal interno: un cliente ve únicamente lo suyo.
      ...(organizationOptions.length > 0
        ? [{ key: 'organization', label: 'Cliente', type: 'select' as const, options: organizationOptions }]
        : []),
      { key: 'equipment', label: 'Equipo o set', type: 'text' },
      { key: 'date_from', label: 'Inspección desde', type: 'date' },
      { key: 'date_to', label: 'Inspección hasta', type: 'date' },
    ],
    [statusOptions, organizationOptions],
  );

  const columns = useMemo<ColumnDef<ReportRow, unknown>[]>(
    () => [
      {
        id: 'number',
        header: 'Nº FMR',
        meta: { sortKey: 'number', locked: true } satisfies ColumnMeta,
        cell: ({ row }) => (
          <span className="font-mono text-[12.5px] font-semibold">{row.original.number}</span>
        ),
      },
      // De qué cliente es el informe. Un FMR sin dueño visible obliga a
      // abrirlo para saber a quién pertenece.
      ...(showOrganization
        ? ([
            {
              id: 'organization',
              header: 'Cliente',
              cell: ({ row }) => (
                <span className="block max-w-[170px] truncate text-[var(--text-secondary)]">
                  {row.original.organization ?? '—'}
                </span>
              ),
            },
          ] as ColumnDef<ReportRow, unknown>[])
        : []),
      {
        id: 'equipment',
        header: 'Equipo',
        meta: { sortKey: 'equipment' } satisfies ColumnMeta,
        cell: ({ row }) => (
          <span className="text-[12.5px]">{row.original.equipment ?? '—'}</span>
        ),
      },
      {
        id: 'inspectedAt',
        header: 'Fecha',
        meta: { sortKey: 'inspected_at' } satisfies ColumnMeta,
        cell: ({ row }) => <span className="tabular">{formatDate(row.original.inspectedAt)}</span>,
      },
      {
        id: 'assetsCount',
        header: 'Piezas',
        meta: { align: 'right' } satisfies ColumnMeta,
        cell: ({ row }) => <span className="tabular">{row.original.assetsCount}</span>,
      },
      {
        id: 'filesCount',
        header: 'Documentos',
        meta: { align: 'right' } satisfies ColumnMeta,
        cell: ({ row }) => <span className="tabular">{row.original.filesCount}</span>,
      },
      {
        id: 'status',
        header: 'Estado',
        meta: { sortKey: 'status' } satisfies ColumnMeta,
        cell: ({ row }) => <Badge tone={row.original.statusTone}>{row.original.statusLabel}</Badge>,
      },
    ],
    [showOrganization],
  );

  return (
    <AppLayout
      title="Informes FMR"
      description="Registro de inspecciones realizadas, con sus piezas y documentación asociada."
      breadcrumbs={[{ label: 'Informes FMR' }]}
      actions={
        can.create && (
          <Dialog open={creating} onOpenChange={setCreating}>
            <DialogTrigger asChild>
              <Button variant="primary" size="sm">
                <Plus aria-hidden />
                Nuevo informe
              </Button>
            </DialogTrigger>
            {creating && (
              <CreateReportDialog
                organizationOptions={organizationOptions}
                serviceOptions={serviceOptions}
                onDone={() => setCreating(false)}
              />
            )}
          </Dialog>
        )
      }
    >
      <Head title="Informes FMR" />

      <Panel>
        <DataTable
          columns={columns}
          page={reports}
          sort={sort}
          baseUrl="/informes"
          query={filters}
          storageKey="reports"
          rowHref={(row) => `/informes/${row.id}`}
          toolbar={
            <FilterBar
              baseUrl="/informes"
              filters={filters}
              definitions={filterDefinitions}
              searchPlaceholder="Número de FMR…"
            />
          }
          emptyState={
            <EmptyState
              icon={FileSearch}
              title="Sin informes"
              description="No hay FMR que coincidan con los filtros aplicados."
              action={
                <Button asChild variant="secondary" size="sm">
                  <Link href="/informes">Quitar filtros</Link>
                </Button>
              }
            />
          }
        />
      </Panel>
    </AppLayout>
  );
}

/**
 * Alta del informe.
 *
 * Nace vacío y en borrador: primero existe el FMR, después se le suman los
 * equipos y se los dictamina desde su propia pantalla. Pedir todo junto acá
 * obligaría a elegir los equipos antes de saber cuáles llegaron.
 */
function CreateReportDialog({
  organizationOptions,
  serviceOptions,
  onDone,
}: {
  organizationOptions: SelectOption[];
  serviceOptions: SelectOption[];
  onDone: () => void;
}) {
  const { data, setData, post, processing, errors, reset } = useForm({
    organization_public_id: '',
    number: '',
    inspected_at: new Date().toISOString().slice(0, 10),
    service_id: '',
    equipment_label: '',
    notes: '',
  });

  const submit = (event: FormEvent) => {
    event.preventDefault();
    post('/informes', {
      onSuccess: () => {
        reset();
        onDone();
      },
    });
  };

  return (
    <DialogContent
      title="Nuevo informe FMR"
      description="Se crea en borrador. Al guardarlo se abre para cargarle los equipos inspeccionados."
      footer={
        <>
          <DialogClose asChild>
            <Button variant="secondary" size="sm">
              Cancelar
            </Button>
          </DialogClose>
          <Button type="submit" form="form-nuevo-informe" variant="primary" size="sm" loading={processing}>
            <FilePlus2 aria-hidden />
            Crear informe
          </Button>
        </>
      }
    >
      <form id="form-nuevo-informe" onSubmit={submit} className="flex flex-col gap-4" noValidate>
        <Field label="Empresa" error={errors.organization_public_id} required>
          {(props) => (
            <Select
              {...props}
              value={data.organization_public_id}
              onChange={(event) => setData('organization_public_id', event.target.value)}
              invalid={Boolean(errors.organization_public_id)}
            >
              <option value="">Elegir empresa…</option>
              {organizationOptions.map((option) => (
                <option key={option.value} value={option.value}>
                  {option.label}
                </option>
              ))}
            </Select>
          )}
        </Field>

        <div className="grid gap-4 sm:grid-cols-2">
          <Field
            label="Número de FMR"
            hint="No puede repetirse dentro de la misma empresa."
            error={errors.number}
            required
          >
            {(props) => (
              <Input
                {...props}
                value={data.number}
                onChange={(event) => setData('number', event.target.value)}
                invalid={Boolean(errors.number)}
              />
            )}
          </Field>

          <Field label="Fecha de inspección" error={errors.inspected_at} required>
            {(props) => (
              <Input
                {...props}
                type="date"
                max={new Date().toISOString().slice(0, 10)}
                value={data.inspected_at}
                onChange={(event) => setData('inspected_at', event.target.value)}
                invalid={Boolean(errors.inspected_at)}
              />
            )}
          </Field>
        </div>

        <Field
          label="Operación"
          hint="Su vigencia es la que se aplica al finalizar, cuando una pieza no traiga vencimiento propio."
          error={errors.service_id}
        >
          {(props) => (
            <Select
              {...props}
              value={data.service_id}
              onChange={(event) => setData('service_id', event.target.value)}
            >
              <option value="">Sin operación asignada</option>
              {serviceOptions.map((option) => (
                <option key={option.value} value={option.value}>
                  {option.label}
                </option>
              ))}
            </Select>
          )}
        </Field>

        <Field label="Equipo o conjunto" hint="Texto libre, para identificar el informe en el listado." error={errors.equipment_label}>
          {(props) => (
            <Input
              {...props}
              value={data.equipment_label}
              onChange={(event) => setData('equipment_label', event.target.value)}
            />
          )}
        </Field>

        <Field label="Observaciones" error={errors.notes}>
          {(props) => (
            <Textarea
              {...props}
              value={data.notes}
              rows={2}
              maxLength={2000}
              onChange={(event) => setData('notes', event.target.value)}
            />
          )}
        </Field>
      </form>
    </DialogContent>
  );
}
