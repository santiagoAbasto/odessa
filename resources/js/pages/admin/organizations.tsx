import { Head, router, useForm } from '@inertiajs/react';
import { Building2, ListChecks, Pencil, Plus } from 'lucide-react';
import { useEffect, useState, type FormEvent } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogClose, DialogContent, DialogTrigger } from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { Field } from '@/components/ui/field';
import { Input, Select, Textarea } from '@/components/ui/input';
import { Panel } from '@/components/ui/panel';
import AppLayout from '@/layouts/app-layout';
import { formatNumber } from '@/lib/format';
import type { Paginated } from '@/types';

interface OrganizationRow {
  id: string;
  name: string;
  shortCode: string;
  taxId: string | null;
  contactEmail: string | null;
  contactPhone: string | null;
  isActive: boolean;
  usersCount: number;
  assetsCount: number;
  receptionsCount: number;
}

export default function Organizations({
  organizations,
  filters,
}: {
  organizations: Paginated<OrganizationRow>;
  filters: Record<string, string | undefined>;
}) {
  const [creating, setCreating] = useState(false);

  return (
    <AppLayout
      title="Organizaciones"
      description="Empresas cliente. Cada usuario y cada equipo pertenece a una, y ese vínculo es lo que aísla la información entre clientes."
      breadcrumbs={[{ label: 'Administración' }, { label: 'Organizaciones' }]}
      actions={
        <Dialog open={creating} onOpenChange={setCreating}>
          <DialogTrigger asChild>
            <Button variant="primary" size="sm">
              <Plus aria-hidden />
              Nueva organización
            </Button>
          </DialogTrigger>
          {creating && <OrganizationDialog onDone={() => setCreating(false)} />}
        </Dialog>
      }
    >
      <Head title="Organizaciones" />

      <Panel>
        <div className="flex flex-wrap items-center gap-2 border-b border-[var(--border-subtle)] px-3 py-2.5">
          <input
            type="search"
            defaultValue={filters.search ?? ''}
            onChange={(event) =>
              router.get(
                '/administracion/organizaciones',
                event.target.value ? { ...filters, search: event.target.value } : {},
                { preserveState: true, preserveScroll: true, replace: true },
              )
            }
            placeholder="Buscar por nombre, código o CUIT…"
            aria-label="Buscar organizaciones"
            className="h-8 w-full max-w-xs rounded-xs border border-[var(--border-default)] bg-[var(--surface-raised)] px-2.5 text-[12.5px] outline-none focus:border-[var(--focus-ring)]"
          />

          <Select
            value={filters.status ?? ''}
            onChange={(event) =>
              router.get(
                '/administracion/organizaciones',
                { ...filters, status: event.target.value || undefined },
                { preserveState: true, preserveScroll: true, replace: true },
              )
            }
            aria-label="Filtrar por estado"
            className="h-8 w-auto text-[12.5px]"
          >
            <option value="">Todos los estados</option>
            <option value="active">Activas</option>
            <option value="inactive">Inactivas</option>
          </Select>
        </div>

        {organizations.data.length === 0 ? (
          <EmptyState
            icon={Building2}
            title="Sin organizaciones"
            description="No hay empresas que coincidan con la búsqueda."
          />
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full min-w-[820px] text-[12.5px]">
              <thead>
                <tr>
                  {['Empresa', 'Contacto', 'Usuarios', 'Equipos', 'En taller', 'Estado', ''].map((header) => (
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
                {organizations.data.map((organization) => (
                  <tr
                    key={organization.id}
                    className="border-b border-[var(--border-subtle)] last:border-b-0 hover:bg-[var(--surface-hover)]"
                  >
                    <td className="px-3 py-2.5">
                      <p className="font-medium">{organization.name}</p>
                      <p className="mt-0.5 text-[11px] text-[var(--text-muted)]">
                        {organization.shortCode}
                        {organization.taxId ? ` · ${organization.taxId}` : ''}
                      </p>
                    </td>
                    <td className="px-3 py-2.5 text-[var(--text-secondary)]">
                      {(() => {
                        const emails = (organization.contactEmail ?? '').split(/[\r\n,;]+/).map((e) => e.trim()).filter(Boolean);
                        if (emails.length === 0) return '—';
                        return (
                          <span>
                            {emails[0]}
                            {emails.length > 1 && (
                              <span className="text-[var(--text-muted)]"> +{emails.length - 1} más</span>
                            )}
                          </span>
                        );
                      })()}
                      {organization.contactPhone && (
                        <p className="mt-0.5 text-[11px] text-[var(--text-muted)]">{organization.contactPhone}</p>
                      )}
                    </td>
                    <td className="px-3 py-2.5 tabular-nums">{formatNumber(organization.usersCount)}</td>
                    <td className="px-3 py-2.5 tabular-nums">{formatNumber(organization.assetsCount)}</td>
                    <td className="px-3 py-2.5 tabular-nums">{formatNumber(organization.receptionsCount)}</td>
                    <td className="px-3 py-2.5">
                      <Badge tone={organization.isActive ? 'success' : 'neutral'}>
                        {organization.isActive ? 'Activa' : 'Inactiva'}
                      </Badge>
                    </td>
                    <td className="px-3 py-2.5 text-right whitespace-nowrap">
                      <OperationsButton organization={organization} />
                      <EditOrganizationButton organization={organization} />
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

interface OrganizationOperation {
  serviceId: number;
  code: string;
  catalogName: string;
  catalogDescription: string | null;
  catalogValidityDays: number | null;
  determinesFitness: boolean;
  enabled: boolean;
  name: string | null;
  description: string | null;
  validityDays: number | null;
}

function OperationsButton({ organization }: { organization: OrganizationRow }) {
  const [open, setOpen] = useState(false);

  return (
    <Dialog open={open} onOpenChange={setOpen}>
      <DialogTrigger asChild>
        <Button variant="ghost" size="sm">
          <ListChecks aria-hidden />
          Operaciones
        </Button>
      </DialogTrigger>
      {open && <OperationsDialog organization={organization} onDone={() => setOpen(false)} />}
    </Dialog>
  );
}

/**
 * Qué operaciones tiene habilitadas esta empresa y cómo se llaman para ella.
 *
 * Odessa lo define por cliente: la misma operación puede tener otro nombre,
 * otra descripción u otra vigencia según la empresa. Un campo vacío deja
 * valer lo del catálogo, que se muestra como sugerencia.
 */
function OperationsDialog({ organization, onDone }: { organization: OrganizationRow; onDone: () => void }) {
  const [rows, setRows] = useState<OrganizationOperation[] | null>(null);
  const [processing, setProcessing] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;

    fetch(`/administracion/organizaciones/${organization.id}/operaciones`, {
      headers: { Accept: 'application/json' },
    })
      .then((response) => (response.ok ? response.json() : Promise.reject()))
      .then((data: OrganizationOperation[]) => {
        if (!cancelled) setRows(data);
      })
      .catch(() => {
        if (!cancelled) setError('No se pudieron cargar las operaciones.');
      });

    return () => {
      cancelled = true;
    };
  }, [organization.id]);

  const update = (serviceId: number, patch: Partial<OrganizationOperation>) =>
    setRows((current) => current?.map((row) => (row.serviceId === serviceId ? { ...row, ...patch } : row)) ?? null);

  const submit = (event: FormEvent) => {
    event.preventDefault();
    if (!rows) return;

    router.put(
      `/administracion/organizaciones/${organization.id}/operaciones`,
      {
        operations: rows.map((row) => ({
          service_id: row.serviceId,
          enabled: row.enabled,
          name: row.name ?? '',
          description: row.description ?? '',
          validity_days: row.validityDays ?? '',
        })),
      },
      {
        preserveScroll: true,
        onStart: () => setProcessing(true),
        onFinish: () => setProcessing(false),
        onSuccess: onDone,
        onError: (errors) => setError(Object.values(errors)[0] ?? 'Revise los datos cargados.'),
      },
    );
  };

  return (
    <DialogContent
      title={`Operaciones de ${organization.name}`}
      description="Las que marque son las que esta empresa puede pedir al armar un envío. El resto lo pide como operación especial."
      className="max-w-3xl"
      footer={
        <>
          <DialogClose asChild>
            <Button variant="secondary" size="sm">
              Cancelar
            </Button>
          </DialogClose>
          <Button type="submit" form="form-operaciones-org" variant="primary" size="sm" loading={processing} disabled={!rows}>
            Guardar
          </Button>
        </>
      }
    >
      <form id="form-operaciones-org" onSubmit={submit} noValidate className="flex flex-col gap-3">
        {error && (
          <p role="alert" className="text-[12px] text-[var(--tone-danger-fg)]">
            {error}
          </p>
        )}

        {rows === null && !error && <p className="text-[12.5px] text-[var(--text-muted)]">Cargando…</p>}

        {rows?.length === 0 && (
          <p className="text-[12.5px] text-[var(--text-muted)]">No hay operaciones activas en el catálogo.</p>
        )}

        {rows?.map((row) => (
          <div
            key={row.serviceId}
            className="rounded-sm border border-[var(--border-default)] p-3"
          >
            <label className="flex items-center gap-2 text-[13px] font-medium">
              <input
                type="checkbox"
                checked={row.enabled}
                onChange={(event) => update(row.serviceId, { enabled: event.target.checked })}
                className="size-3.5 rounded-xs accent-[var(--accent)]"
              />
              {row.catalogName}
              <span className="font-mono text-[10.5px] font-normal text-[var(--text-muted)]">{row.code}</span>
            </label>

            {row.enabled && (
              <div className="mt-3 grid gap-3 sm:grid-cols-[1fr_1fr_120px]">
                <Field label="Nombre para esta empresa">
                  {(props) => (
                    <Input
                      {...props}
                      value={row.name ?? ''}
                      placeholder={row.catalogName}
                      maxLength={120}
                      onChange={(event) => update(row.serviceId, { name: event.target.value })}
                    />
                  )}
                </Field>
                <Field label="En qué consiste">
                  {(props) => (
                    <Input
                      {...props}
                      value={row.description ?? ''}
                      placeholder={row.catalogDescription ?? 'Descripción breve del trabajo'}
                      maxLength={1000}
                      onChange={(event) => update(row.serviceId, { description: event.target.value })}
                    />
                  )}
                </Field>
                {row.determinesFitness ? (
                  <Field label="Vigencia (días)">
                    {(props) => (
                      <Input
                        {...props}
                        type="number"
                        min={1}
                        max={3650}
                        value={row.validityDays ?? ''}
                        placeholder={row.catalogValidityDays?.toString() ?? 'Por defecto'}
                        onChange={(event) =>
                          update(row.serviceId, {
                            validityDays: event.target.value === '' ? null : Number(event.target.value),
                          })
                        }
                      />
                    )}
                  </Field>
                ) : (
                  <div />
                )}
              </div>
            )}
          </div>
        ))}
      </form>
    </DialogContent>
  );
}

function EditOrganizationButton({ organization }: { organization: OrganizationRow }) {
  const [open, setOpen] = useState(false);

  return (
    <Dialog open={open} onOpenChange={setOpen}>
      <DialogTrigger asChild>
        <Button variant="ghost" size="sm">
          <Pencil aria-hidden />
          Editar
        </Button>
      </DialogTrigger>
      {/* Se monta al abrir para que el formulario traiga los datos de esta
          fila y no los de la última que se editó. */}
      {open && <OrganizationDialog organization={organization} onDone={() => setOpen(false)} />}
    </Dialog>
  );
}

/**
 * Alta y edición de una empresa cliente.
 *
 * No hay opción de borrar: una organización con equipos, informes y
 * recepciones no se puede eliminar sin dejar huérfano todo ese historial.
 * Desactivarla corta el acceso de sus usuarios y conserva la trazabilidad.
 */
function OrganizationDialog({
  organization,
  onDone,
}: {
  organization?: OrganizationRow;
  onDone: () => void;
}) {
  const editing = organization !== undefined;

  const { data, setData, post, put, processing, errors, reset } = useForm({
    name: organization?.name ?? '',
    short_code: organization?.shortCode ?? '',
    tax_id: organization?.taxId ?? '',
    contact_email: organization?.contactEmail ?? '',
    contact_phone: organization?.contactPhone ?? '',
    is_active: organization?.isActive ?? true,
  });

  const submit = (event: FormEvent) => {
    event.preventDefault();

    const onSuccess = () => {
      reset();
      onDone();
    };

    if (editing) {
      put(`/administracion/organizaciones/${organization.id}`, { preserveScroll: true, onSuccess });
    } else {
      post('/administracion/organizaciones', { preserveScroll: true, onSuccess });
    }
  };

  return (
    <DialogContent
      title={editing ? `Editar ${organization.name}` : 'Nueva organización'}
      description={
        editing
          ? 'Los cambios quedan registrados en la auditoría.'
          : 'Después podrá darle de alta usuarios y equipos desde sus propias pantallas.'
      }
      footer={
        <>
          <DialogClose asChild>
            <Button variant="secondary" size="sm">
              Cancelar
            </Button>
          </DialogClose>
          <Button type="submit" form="form-organizacion" variant="primary" size="sm" loading={processing}>
            <Building2 aria-hidden />
            {editing ? 'Guardar cambios' : 'Crear organización'}
          </Button>
        </>
      }
    >
      <form id="form-organizacion" onSubmit={submit} className="flex flex-col gap-4" noValidate>
        <Field label="Razón social" error={errors.name} required>
          {(props) => (
            <Input
              {...props}
              value={data.name}
              onChange={(event) => setData('name', event.target.value)}
              invalid={Boolean(errors.name)}
            />
          )}
        </Field>

        <div className="grid gap-4 sm:grid-cols-2">
          <Field
            label="Código corto"
            hint="Identifica a la empresa en listados y exportaciones. Se guarda en mayúsculas."
            error={errors.short_code}
            required
          >
            {(props) => (
              <Input
                {...props}
                value={data.short_code}
                maxLength={16}
                onChange={(event) => setData('short_code', event.target.value.toUpperCase())}
                invalid={Boolean(errors.short_code)}
              />
            )}
          </Field>

          <Field label="CUIT" error={errors.tax_id}>
            {(props) => (
              <Input {...props} value={data.tax_id} onChange={(event) => setData('tax_id', event.target.value)} />
            )}
          </Field>
        </div>

        <div className="grid gap-4 sm:grid-cols-2">
          <Field
            label="Correos de contacto"
            hint="Uno por línea. A estas casillas llegan los avisos, aunque no sean usuarios del sistema."
            error={errors.contact_email}
          >
            {(props) => (
              <Textarea
                {...props}
                rows={3}
                value={data.contact_email}
                onChange={(event) => setData('contact_email', event.target.value)}
                placeholder={'compras@empresa.com\noperaciones@empresa.com'}
                invalid={Boolean(errors.contact_email)}
              />
            )}
          </Field>

          <Field label="Teléfono" error={errors.contact_phone}>
            {(props) => (
              <Input
                {...props}
                type="tel"
                value={data.contact_phone}
                onChange={(event) => setData('contact_phone', event.target.value)}
              />
            )}
          </Field>
        </div>

        {editing && (
          <Field
            label="Estado"
            hint="Desactivarla corta el acceso de todos sus usuarios. Los equipos y el historial se conservan."
          >
            {() => (
              <label className="flex items-center gap-2 text-[12.5px]">
                <input
                  type="checkbox"
                  checked={data.is_active}
                  onChange={(event) => setData('is_active', event.target.checked)}
                  className="size-3.5 rounded-xs accent-[var(--accent)]"
                />
                Activa
              </label>
            )}
          </Field>
        )}
      </form>
    </DialogContent>
  );
}
