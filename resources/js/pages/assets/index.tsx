import { Head, Link, useForm } from '@inertiajs/react';
import type { ColumnDef } from '@tanstack/react-table';
import { Boxes, PackagePlus, PackageSearch, Plus, Sheet, Wrench } from 'lucide-react';
import { useMemo, useState, type FormEvent } from 'react';
import { DataTable, type ColumnMeta } from '@/components/tables/data-table';
import { FilterBar, type FilterDefinition } from '@/components/tables/filter-bar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogClose, DialogContent, DialogTrigger } from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { Field } from '@/components/ui/field';
import { Input, Select } from '@/components/ui/input';
import { Panel } from '@/components/ui/panel';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/cn';
import { expiryLabel, formatDate } from '@/lib/format';
import type { AssetRow, Paginated, SelectOption, SortState } from '@/types';

interface AssetsIndexProps {
  kind: 'part' | 'generic';
  assets: Paginated<AssetRow>;
  filters: Record<string, string | undefined>;
  sort: SortState;
  statusOptions: SelectOption[];
  /** Falso para un cliente: todo lo que ve ya es suyo. */
  showOrganization: boolean;
  organizationOptions: SelectOption[];
  can: { create: boolean };
}

export default function AssetsIndex({
  kind,
  assets,
  filters,
  sort,
  statusOptions,
  showOrganization,
  organizationOptions,
  can,
}: AssetsIndexProps) {
  const isGeneric = kind === 'generic';
  const [creating, setCreating] = useState(false);

  const filterDefinitions = useMemo<FilterDefinition[]>(
    () => [
      { key: 'search', label: 'Búsqueda', type: 'text' },
      { key: 'status', label: 'Estado', type: 'select', options: statusOptions },
      ...(showOrganization
        ? ([
            { key: 'organization', label: 'Cliente', type: 'select', options: organizationOptions },
          ] as FilterDefinition[])
        : []),
      { key: 'serial', label: 'Número de serie', type: 'text' },
      { key: 'code', label: 'Código', type: 'text' },
      { key: 'client_reference', label: 'Referencia del cliente', type: 'text' },
      { key: 'report_number', label: 'Número de FMR', type: 'text' },
      /*
        Ventanas listas en vez de obligar a calcular la fecha a mano.
        "Hay que mandarlo antes de" es distinto de "vence en": contempla el
        tiempo que el equipo pasa en el taller, que es lo que en la práctica
        decide cuándo el cliente tiene que despacharlo.
      */
      {
        key: 'due_in',
        label: 'Vence dentro de',
        type: 'select',
        options: [
          { value: '30', label: '30 días' },
          { value: '60', label: '60 días' },
          { value: '90', label: '90 días' },
          { value: '180', label: '6 meses' },
        ],
      },
      {
        key: 'send_by_in',
        label: 'Hay que mandarlo antes de',
        type: 'select',
        options: [
          { value: '15', label: '15 días' },
          { value: '30', label: '30 días' },
          { value: '60', label: '60 días' },
        ],
      },
      {
        key: 'overdue',
        label: 'Fuera de plazo',
        type: 'select',
        options: [{ value: '1', label: 'Vencidos o sin tiempo de envío' }],
      },
      { key: 'expires_from', label: 'Vence desde', type: 'date' },
      { key: 'expires_to', label: 'Vence hasta', type: 'date' },
      ...(isGeneric
        ? ([
            { key: 'brand', label: 'Marca', type: 'text' },
            { key: 'model', label: 'Modelo', type: 'text' },
          ] as FilterDefinition[])
        : []),
    ],
    [statusOptions, isGeneric, showOrganization, organizationOptions],
  );

  const columns = useMemo<ColumnDef<AssetRow, unknown>[]>(() => {
    const base: ColumnDef<AssetRow, unknown>[] = [
      {
        id: 'name',
        header: isGeneric ? 'Genérico' : 'Parte',
        meta: { sortKey: 'description', locked: true } satisfies ColumnMeta,
        cell: ({ row }) => (
          <div className="min-w-0">
            <p className="truncate font-medium">{row.original.name}</p>
            <p className="mt-0.5 truncate font-mono text-[11px] text-[var(--text-muted)]">
              {isGeneric
                ? [row.original.brand, row.original.model].filter(Boolean).join(' · ') || '—'
                : (row.original.partNumber ?? '—')}
            </p>
          </div>
        ),
      },
      /*
        De quién es el equipo. Va pegada al nombre porque, desde Odessa, saber
        a qué cliente pertenece la pieza es tan identificatorio como su
        descripción. A un cliente no se le muestra: todo lo que ve es suyo.
      */
      ...(showOrganization
        ? ([
            {
              id: 'organization',
              header: 'Cliente',
              cell: ({ row }) => (
                <span className="block max-w-[180px] truncate text-[var(--text-secondary)]">
                  {row.original.organization ?? '—'}
                </span>
              ),
            },
          ] as ColumnDef<AssetRow, unknown>[])
        : []),
      {
        id: 'serialNumber',
        header: isGeneric ? 'ID / Serie' : 'Nº de serie',
        meta: { sortKey: 'serial' } satisfies ColumnMeta,
        cell: ({ row }) => (
          <span className="font-mono text-[11.5px]">
            {row.original.serialNumber ?? row.original.identifier ?? '—'}
          </span>
        ),
      },
      {
        id: 'code',
        header: 'Código',
        meta: { sortKey: 'code' } satisfies ColumnMeta,
        cell: ({ row }) => (
          <span className="font-mono text-[11.5px] text-[var(--text-secondary)]">
            {row.original.code ?? '—'}
          </span>
        ),
      },
      {
        id: 'clientReference',
        header: 'Ref. cliente',
        meta: { sortKey: 'client_reference' } satisfies ColumnMeta,
        cell: ({ row }) => (
          <span className="font-mono text-[11.5px] text-[var(--text-secondary)]">
            {row.original.clientReference ?? '—'}
          </span>
        ),
      },
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
        id: 'expiresAt',
        header: 'Vencimiento',
        meta: { sortKey: 'expires_at' } satisfies ColumnMeta,
        cell: ({ row }) => (
          <div>
            <p className="tabular">{formatDate(row.original.expiresAt)}</p>
            <p
              className={cn(
                'mt-0.5 text-[10.5px]',
                row.original.status === 'unfit'
                  ? 'text-[var(--tone-danger-fg)]'
                  : row.original.status === 'expiring'
                    ? 'text-[var(--tone-warning-fg)]'
                    : 'text-[var(--text-muted)]',
              )}
            >
              {expiryLabel(row.original.expiresAt)}
            </p>
          </div>
        ),
      },
      {
        id: 'status',
        header: 'Estado',
        meta: { sortKey: 'status' } satisfies ColumnMeta,
        cell: ({ row }) => <Badge tone={row.original.statusTone}>{row.original.statusLabel}</Badge>,
      },
    ];

    return base;
  }, [isGeneric, showOrganization]);

  return (
    <AppLayout
      title="Activos"
      description="Inventario de piezas y equipamiento genérico con su estado de aptitud vigente."
      breadcrumbs={[{ label: 'Activos' }]}
      actions={
        <>
          {/*
            La descarga arrastra los filtros activos: se baja exactamente lo
            que se está viendo. Es un enlace y no un botón con fetch porque el
            navegador tiene que encargarse de guardar el archivo.
          */}
          <Button asChild variant="secondary" size="sm">
            <a
              href={`/activos/exportar?${new URLSearchParams(
                Object.entries({ ...filters, kind }).filter(([, value]) => value) as [string, string][],
              ).toString()}`}
            >
              <Sheet aria-hidden />
              Exportar a Excel
            </a>
          </Button>

          {can.create && (
            <Dialog open={creating} onOpenChange={setCreating}>
              <DialogTrigger asChild>
                <Button variant="primary" size="sm">
                  <Plus aria-hidden />
                  Nuevo equipo
                </Button>
              </DialogTrigger>
              {creating && (
                <CreateAssetDialog
                  kind={kind}
                  organizationOptions={organizationOptions}
                  onDone={() => setCreating(false)}
                />
              )}
            </Dialog>
          )}
        </>
      }
    >
      <Head title="Activos" />

      {/* Conmutador de tipo: reemplaza los dos menús separados del sistema anterior. */}
      <div
        role="tablist"
        aria-label="Tipo de activo"
        className="mb-4 inline-flex rounded-sm border border-[var(--border-default)] bg-[var(--surface-raised)] p-0.5"
      >
        {[
          { value: 'part', label: 'Partes', icon: Wrench },
          { value: 'generic', label: 'Genéricos', icon: Boxes },
        ].map((tab) => (
          <Link
            key={tab.value}
            href="/activos"
            data={{ kind: tab.value }}
            preserveScroll
            role="tab"
            aria-selected={kind === tab.value}
            className={cn(
              'inline-flex items-center gap-1.5 rounded-xs px-3 py-1.5 text-[12.5px] transition-colors',
              kind === tab.value
                ? 'bg-[var(--accent)] font-medium text-[var(--text-inverse)]'
                : 'text-[var(--text-secondary)] hover:bg-[var(--surface-hover)]',
            )}
          >
            <tab.icon className="size-3.5" aria-hidden />
            {tab.label}
          </Link>
        ))}
      </div>

      <Panel>
        <DataTable
          columns={columns}
          page={assets}
          sort={sort}
          baseUrl="/activos"
          query={{ ...filters, kind }}
          storageKey={`assets.${kind}`}
          rowHref={(row) => `/activos/${row.id}`}
          toolbar={
            <FilterBar
              baseUrl="/activos"
              filters={{ ...filters, kind }}
              definitions={filterDefinitions}
              searchPlaceholder="Descripción, serie, código o referencia…"
            />
          }
          emptyState={
            <EmptyState
              icon={PackageSearch}
              title="Sin resultados"
              description="No hay activos que coincidan con los filtros aplicados."
              action={
                <Button asChild variant="secondary" size="sm">
                  <Link href={`/activos?kind=${kind}`}>Quitar filtros</Link>
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
 * Alta manual de un equipo.
 *
 * Pide lo mínimo para identificarlo. El resto de la ficha técnica —presiones,
 * conexiones, fabricante— se completa después desde la pantalla del equipo,
 * que ya tiene ese formulario: repetirlo acá obligaría a mantener dos.
 */
function CreateAssetDialog({
  kind,
  organizationOptions,
  onDone,
}: {
  kind: 'part' | 'generic';
  organizationOptions: SelectOption[];
  onDone: () => void;
}) {
  const { data, setData, post, processing, errors, reset } = useForm({
    organization_public_id: '',
    kind,
    description: '',
    serial_number: '',
    client_reference: '',
    brand: '',
    model: '',
  });

  const submit = (event: FormEvent) => {
    event.preventDefault();
    post('/activos', {
      preserveScroll: true,
      onSuccess: () => {
        reset();
        onDone();
      },
    });
  };

  return (
    <DialogContent
      title={kind === 'generic' ? 'Nuevo equipamiento genérico' : 'Nueva pieza'}
      description="Queda en el inventario sin certificación hasta que se le cargue un informe."
      footer={
        <>
          <DialogClose asChild>
            <Button variant="secondary" size="sm">
              Cancelar
            </Button>
          </DialogClose>
          <Button type="submit" form="form-nuevo-activo" variant="primary" size="sm" loading={processing}>
            <PackagePlus aria-hidden />
            Dar de alta
          </Button>
        </>
      }
    >
      <form id="form-nuevo-activo" onSubmit={submit} className="flex flex-col gap-4" noValidate>
        {/* Un cliente no elige: se le asigna su propia empresa. */}
        {organizationOptions.length > 0 && (
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
        )}

        <Field label="Descripción" error={errors.description} required>
          {(props) => (
            <Input
              {...props}
              value={data.description}
              onChange={(event) => setData('description', event.target.value)}
              invalid={Boolean(errors.description)}
            />
          )}
        </Field>

        <div className="grid gap-4 sm:grid-cols-2">
          <Field
            label="Número de serie"
            hint="Si ya existe una pieza de esa empresa con esta serie, el alta se rechaza: sería la misma pieza duplicada."
            error={errors.serial_number}
          >
            {(props) => (
              <Input
                {...props}
                value={data.serial_number}
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
                onChange={(event) => setData('client_reference', event.target.value)}
              />
            )}
          </Field>
        </div>

        {kind === 'generic' && (
          <div className="grid gap-4 sm:grid-cols-2">
            <Field label="Marca" error={errors.brand}>
              {(props) => (
                <Input {...props} value={data.brand} onChange={(event) => setData('brand', event.target.value)} />
              )}
            </Field>

            <Field label="Modelo" error={errors.model}>
              {(props) => (
                <Input {...props} value={data.model} onChange={(event) => setData('model', event.target.value)} />
              )}
            </Field>
          </div>
        )}
      </form>
    </DialogContent>
  );
}
