import { Head, Link, router, useForm } from '@inertiajs/react';
import type { ColumnDef } from '@tanstack/react-table';
import { Package, PackageSearch, Pencil, Plus, Trash2 } from 'lucide-react';
import { useMemo, useState, type FormEvent } from 'react';
import { DataTable, type ColumnMeta } from '@/components/tables/data-table';
import { FilterBar, type FilterDefinition } from '@/components/tables/filter-bar';
import { Button } from '@/components/ui/button';
import { ConfirmButton } from '@/components/ui/confirm-button';
import { Dialog, DialogClose, DialogContent, DialogTrigger } from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { Field } from '@/components/ui/field';
import { Input, Select } from '@/components/ui/input';
import { Panel } from '@/components/ui/panel';
import AppLayout from '@/layouts/app-layout';
import type { Paginated, SelectOption, SortState } from '@/types';

interface Product {
  id: number;
  /** Corto, el que usa el negocio todos los días. Puede faltar. */
  partNumber: string | null;
  description: string;
  kind: 'part' | 'generic';
  manufacturerId: number | null;
  manufacturer: string | null;
  /** Cuántas unidades, de todos los clientes, son de este producto. */
  assetsCount: number;
}

const KIND_LABEL: Record<Product['kind'], string> = {
  part: 'Pieza',
  generic: 'Genérico',
};

export default function Products({
  products,
  filters,
  sort,
  manufacturerOptions,
  can,
}: {
  products: Paginated<Product>;
  filters: Record<string, string | undefined>;
  sort: SortState;
  manufacturerOptions: SelectOption[];
  can: { manage: boolean };
}) {
  const [creating, setCreating] = useState(false);
  const [editing, setEditing] = useState<Product | null>(null);

  const filterDefinitions = useMemo<FilterDefinition[]>(
    () => [
      {
        key: 'kind',
        label: 'Tipo',
        type: 'select',
        options: [
          { value: 'part', label: 'Pieza' },
          { value: 'generic', label: 'Genérico' },
        ],
      },
      { key: 'manufacturer', label: 'Fabricante', type: 'select', options: manufacturerOptions },
      {
        key: 'unused',
        label: 'Sin equipos',
        type: 'select',
        options: [{ value: '1', label: 'Sólo los que no se usan' }],
      },
    ],
    [manufacturerOptions],
  );

  const columns = useMemo<ColumnDef<Product, unknown>[]>(
    () => [
      {
        id: 'description',
        header: 'Descripción Odessa',
        meta: { sortKey: 'description', locked: true } satisfies ColumnMeta,
        cell: ({ row }) => (
          <div className="min-w-0">
            <p className="truncate font-medium">{row.original.description}</p>
            <p className="mt-0.5 truncate font-mono text-[11px] text-[var(--text-muted)]">
              {row.original.partNumber ?? 'sin número de parte'}
            </p>
          </div>
        ),
      },
      {
        id: 'kind',
        header: 'Tipo',
        meta: { sortKey: 'kind' } satisfies ColumnMeta,
        cell: ({ row }) => (
          <span className="text-[var(--text-secondary)]">{KIND_LABEL[row.original.kind]}</span>
        ),
      },
      {
        id: 'manufacturer',
        header: 'Fabricante',
        cell: ({ row }) => (
          <span className="block max-w-[180px] truncate text-[var(--text-secondary)]">
            {row.original.manufacturer ?? '—'}
          </span>
        ),
      },
      /*
        El conteo es un enlace a propósito: es la pregunta para la que existe
        el catálogo. Elegís el producto y se abren las unidades que hay en la
        calle, de todos los clientes.
      */
      {
        id: 'assetsCount',
        header: 'Equipos',
        meta: { sortKey: 'assets_count', align: 'right' } satisfies ColumnMeta,
        cell: ({ row }) =>
          row.original.assetsCount === 0 ? (
            <span className="block text-right text-[var(--text-muted)]">—</span>
          ) : (
            <Link
              href={`/activos?kind=${row.original.kind}&product=${row.original.id}`}
              className="block text-right font-medium underline-offset-2 hover:underline"
              onClick={(event) => event.stopPropagation()}
            >
              {row.original.assetsCount}
            </Link>
          ),
      },
      ...(can.manage
        ? ([
            {
              id: 'actions',
              header: '',
              meta: { align: 'right', locked: true } satisfies ColumnMeta,
              cell: ({ row }) => (
                <div
                  className="flex justify-end gap-1"
                  onClick={(event) => event.stopPropagation()}
                >
                  <Button
                    variant="ghost"
                    size="sm"
                    aria-label={`Editar ${row.original.description}`}
                    onClick={() => setEditing(row.original)}
                  >
                    <Pencil aria-hidden />
                  </Button>

                  {row.original.assetsCount === 0 && (
                    <ConfirmButton
                      title={`Borrar «${row.original.description}»`}
                      description="No hay ningún equipo clasificado con este producto, así que no se pierde historial."
                      confirmLabel="Borrar"
                      destructive
                      onConfirm={() =>
                        router.delete(`/administracion/productos/${row.original.id}`, {
                          preserveScroll: true,
                        })
                      }
                    >
                      <Button
                        variant="ghost"
                        size="sm"
                        aria-label={`Borrar ${row.original.description}`}
                      >
                        <Trash2 aria-hidden />
                      </Button>
                    </ConfirmButton>
                  )}
                </div>
              ),
            },
          ] as ColumnDef<Product, unknown>[])
        : []),
    ],
    [can.manage],
  );

  return (
    <AppLayout
      title="Productos"
      description="El qué es de cada pieza, compartido entre clientes. De cada producto cuelgan las series de todas las empresas."
      breadcrumbs={[{ label: 'Administración' }, { label: 'Productos' }]}
      actions={
        can.manage ? (
          <Dialog open={creating} onOpenChange={setCreating}>
            <DialogTrigger asChild>
              <Button variant="primary" size="sm">
                <Plus aria-hidden />
                Nuevo producto
              </Button>
            </DialogTrigger>
            <ProductDialog
              manufacturerOptions={manufacturerOptions}
              onDone={() => setCreating(false)}
            />
          </Dialog>
        ) : undefined
      }
    >
      <Head title="Productos" />

      <Panel>
        <DataTable
          columns={columns}
          page={products}
          sort={sort}
          baseUrl="/administracion/productos"
          query={filters}
          storageKey="products"
          toolbar={
            <FilterBar
              baseUrl="/administracion/productos"
              filters={filters}
              definitions={filterDefinitions}
              searchPlaceholder="Número de parte o descripción…"
            />
          }
          emptyState={
            <EmptyState
              icon={PackageSearch}
              title="Sin productos"
              description="No hay productos que coincidan con la búsqueda."
              action={
                <Button asChild variant="secondary" size="sm">
                  <Link href="/administracion/productos">Quitar filtros</Link>
                </Button>
              }
            />
          }
        />
      </Panel>

      {/* Un solo diálogo para editar, montado por producto para que arranque
          con los valores de ese y no con los del anterior. */}
      {editing !== null && (
        <Dialog open onOpenChange={(open) => !open && setEditing(null)}>
          <ProductDialog
            key={editing.id}
            product={editing}
            manufacturerOptions={manufacturerOptions}
            onDone={() => setEditing(null)}
          />
        </Dialog>
      )}
    </AppLayout>
  );
}

function ProductDialog({
  product,
  manufacturerOptions,
  onDone,
}: {
  product?: Product;
  manufacturerOptions: SelectOption[];
  onDone: () => void;
}) {
  const isEdit = product !== undefined;

  const { data, setData, post, put, processing, errors } = useForm({
    description: product?.description ?? '',
    part_number: product?.partNumber ?? '',
    kind: product?.kind ?? 'part',
    manufacturer_id: product?.manufacturerId?.toString() ?? '',
  });

  const submit = (event: FormEvent) => {
    event.preventDefault();

    const options = { onSuccess: onDone, preserveScroll: true };

    if (isEdit) {
      put(`/administracion/productos/${product.id}`, options);
    } else {
      post('/administracion/productos', options);
    }
  };

  return (
    <DialogContent
      title={isEdit ? `Editar «${product.description}»` : 'Nuevo producto'}
      description="La descripción es lo que el cliente ve al elegir qué manda al taller."
      className="max-w-lg"
      footer={
        <>
          <DialogClose asChild>
            <Button variant="secondary" size="sm">
              Cancelar
            </Button>
          </DialogClose>
          <Button type="submit" form="form-producto" variant="primary" size="sm" loading={processing}>
            {isEdit ? 'Guardar' : 'Crear'}
          </Button>
        </>
      }
    >
      <form id="form-producto" onSubmit={submit} className="flex flex-col gap-4" noValidate>
        <Field
          label="Descripción Odessa"
          hint={
            isEdit && product.assetsCount > 0
              ? `Cambiarla la cambia en los ${product.assetsCount} equipos que usan este producto.`
              : 'Como la nombra Odessa, no como la nombra el cliente.'
          }
          error={errors.description}
          required
        >
          {(props) => (
            <Input
              {...props}
              value={data.description}
              maxLength={255}
              onChange={(event) => setData('description', event.target.value)}
              invalid={Boolean(errors.description)}
            />
          )}
        </Field>

        <div className="grid gap-4 sm:grid-cols-2">
          <Field
            label="Número de parte"
            hint="Opcional. Es por donde se busca cuando se lo sabe."
            error={errors.part_number}
          >
            {(props) => (
              <Input
                {...props}
                value={data.part_number}
                maxLength={255}
                onChange={(event) => setData('part_number', event.target.value)}
                invalid={Boolean(errors.part_number)}
              />
            )}
          </Field>

          <Field label="Tipo" error={errors.kind} required>
            {(props) => (
              <Select
                {...props}
                value={data.kind}
                onChange={(event) => setData('kind', event.target.value as Product['kind'])}
              >
                <option value="part">Pieza</option>
                <option value="generic">Genérico</option>
              </Select>
            )}
          </Field>
        </div>

        <Field label="Fabricante" hint="Opcional." error={errors.manufacturer_id}>
          {(props) => (
            <Select
              {...props}
              value={data.manufacturer_id}
              onChange={(event) => setData('manufacturer_id', event.target.value)}
            >
              <option value="">Sin especificar</option>
              {manufacturerOptions.map((option) => (
                <option key={option.value} value={option.value}>
                  {option.label}
                </option>
              ))}
            </Select>
          )}
        </Field>
      </form>
    </DialogContent>
  );
}
