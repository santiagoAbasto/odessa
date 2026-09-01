import { Head, Link, router, useForm } from '@inertiajs/react';
import { CircleSlash, Plus, ShieldCheck, UserCog, Users } from 'lucide-react';
import { useState, type FormEvent } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { ConfirmButton } from '@/components/ui/confirm-button';
import { Dialog, DialogClose, DialogContent, DialogTrigger } from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { Field } from '@/components/ui/field';
import { Input, Select } from '@/components/ui/input';
import { Panel } from '@/components/ui/panel';
import AppLayout from '@/layouts/app-layout';
import { formatRelative } from '@/lib/format';
import type { Paginated, SelectOption } from '@/types';

interface UserRow {
  id: string;
  name: string;
  email: string;
  jobTitle: string | null;
  phone: string | null;
  isActive: boolean;
  isStaff: boolean;
  organization: string | null;
  roles: string[];
  lastLoginAt: string | null;
  canEdit: boolean;
  canDelete: boolean;
}

interface UsersIndexProps {
  users: Paginated<UserRow>;
  filters: Record<string, string | undefined>;
  roleOptions: SelectOption[];
  organizations: { id: string; name: string }[];
  can: { create: boolean };
}

export default function UsersIndex({ users, filters, roleOptions, organizations, can }: UsersIndexProps) {
  const [creating, setCreating] = useState(false);

  return (
    <AppLayout
      title="Usuarios"
      description="Altas, roles y estado de acceso. La contraseña la define cada usuario desde el enlace que recibe por correo."
      breadcrumbs={[{ label: 'Administración' }, { label: 'Usuarios' }]}
      actions={
        can.create && (
          <Dialog open={creating} onOpenChange={setCreating}>
            <DialogTrigger asChild>
              <Button variant="primary" size="sm">
                <Plus aria-hidden />
                Nuevo usuario
              </Button>
            </DialogTrigger>
            <CreateUserDialog
              roleOptions={roleOptions}
              organizations={organizations}
              onDone={() => setCreating(false)}
            />
          </Dialog>
        )
      }
    >
      <Head title="Usuarios" />

      <Panel>
        <div className="flex flex-wrap items-center gap-2 border-b border-[var(--border-subtle)] px-3 py-2.5">
          <input
            type="search"
            defaultValue={filters.search ?? ''}
            onChange={(event) =>
              router.get(
                '/administracion/usuarios',
                event.target.value ? { ...filters, search: event.target.value } : {},
                { preserveState: true, preserveScroll: true, replace: true },
              )
            }
            placeholder="Buscar por nombre o correo…"
            aria-label="Buscar usuarios"
            className="h-8 w-full max-w-xs rounded-xs border border-[var(--border-default)] bg-[var(--surface-raised)] px-2.5 text-[12.5px] outline-none focus:border-[var(--focus-ring)]"
          />

          <Select
            value={filters.status ?? ''}
            onChange={(event) =>
              router.get(
                '/administracion/usuarios',
                { ...filters, status: event.target.value || undefined },
                { preserveState: true, preserveScroll: true, replace: true },
              )
            }
            aria-label="Filtrar por estado"
            className="h-8 w-auto text-[12.5px]"
          >
            <option value="">Todos los estados</option>
            <option value="active">Activos</option>
            <option value="inactive">Inactivos</option>
          </Select>
        </div>

        {users.data.length === 0 ? (
          <EmptyState icon={Users} title="Sin usuarios" description="No hay usuarios que coincidan." />
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full min-w-[720px] text-[12.5px]">
              <thead>
                <tr>
                  {['Usuario', 'Organización', 'Roles', 'Último acceso', 'Estado', ''].map((header) => (
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
                {users.data.map((user) => (
                  <tr
                    key={user.id}
                    className="border-b border-[var(--border-subtle)] last:border-b-0 hover:bg-[var(--surface-hover)]"
                  >
                    <td className="px-3 py-2.5">
                      <p className="font-medium">{user.name}</p>
                      <p className="mt-0.5 text-[11px] text-[var(--text-muted)]">{user.email}</p>
                    </td>
                    <td className="px-3 py-2.5">
                      {user.isStaff ? (
                        <span className="inline-flex items-center gap-1.5 text-[var(--text-accent)]">
                          <ShieldCheck className="size-3.5" aria-hidden />
                          Personal Odessa
                        </span>
                      ) : (
                        (user.organization ?? '—')
                      )}
                    </td>
                    <td className="px-3 py-2.5">
                      <div className="flex flex-wrap gap-1">
                        {user.roles.map((role) => (
                          <Badge key={role} tone="neutral" withIcon={false}>
                            {role}
                          </Badge>
                        ))}
                      </div>
                    </td>
                    <td className="px-3 py-2.5 text-[var(--text-secondary)]">
                      {formatRelative(user.lastLoginAt)}
                    </td>
                    <td className="px-3 py-2.5">
                      <Badge tone={user.isActive ? 'success' : 'neutral'}>
                        {user.isActive ? 'Activo' : 'Inactivo'}
                      </Badge>
                    </td>
                    <td className="px-3 py-2.5 text-right">
                      {user.canDelete && (
                        <ConfirmButton
                          variant="ghost"
                          size="sm"
                          destructive
                          title={`Desactivar a ${user.name}`}
                          description="El usuario perderá el acceso en su siguiente petición. Se conserva su historial de auditoría."
                          confirmLabel="Desactivar"
                          onConfirm={() =>
                            router.delete(`/administracion/usuarios/${user.id}`, { preserveScroll: true })
                          }
                        >
                          <CircleSlash aria-hidden />
                          Desactivar
                        </ConfirmButton>
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

function CreateUserDialog({
  roleOptions,
  organizations,
  onDone,
}: {
  roleOptions: SelectOption[];
  organizations: { id: string; name: string }[];
  onDone: () => void;
}) {
  const { data, setData, post, processing, errors, reset } = useForm({
    name: '',
    email: '',
    phone: '',
    job_title: '',
    organization_id: '',
    roles: [] as string[],
  });

  const submit = (event: FormEvent) => {
    event.preventDefault();
    post('/administracion/usuarios', {
      preserveScroll: true,
      onSuccess: () => {
        reset();
        onDone();
      },
    });
  };

  return (
    <DialogContent
      title="Nuevo usuario"
      description="Se enviará un enlace al correo indicado para que defina su contraseña."
      footer={
        <>
          <DialogClose asChild>
            <Button variant="secondary" size="sm">
              Cancelar
            </Button>
          </DialogClose>
          <Button type="submit" form="form-nuevo-usuario" variant="primary" size="sm" loading={processing}>
            <UserCog aria-hidden />
            Crear usuario
          </Button>
        </>
      }
    >
      <form id="form-nuevo-usuario" onSubmit={submit} className="flex flex-col gap-4" noValidate>
        <Field label="Nombre y apellido" error={errors.name} required>
          {(props) => (
            <Input
              {...props}
              value={data.name}
              onChange={(event) => setData('name', event.target.value)}
              invalid={Boolean(errors.name)}
            />
          )}
        </Field>

        <Field label="Correo electrónico" error={errors.email} required>
          {(props) => (
            <Input
              {...props}
              type="email"
              value={data.email}
              onChange={(event) => setData('email', event.target.value)}
              invalid={Boolean(errors.email)}
            />
          )}
        </Field>

        <div className="grid gap-4 sm:grid-cols-2">
          <Field label="Teléfono" error={errors.phone}>
            {(props) => (
              <Input
                {...props}
                type="tel"
                value={data.phone}
                onChange={(event) => setData('phone', event.target.value)}
              />
            )}
          </Field>

          <Field label="Puesto" error={errors.job_title}>
            {(props) => (
              <Input
                {...props}
                value={data.job_title}
                onChange={(event) => setData('job_title', event.target.value)}
              />
            )}
          </Field>
        </div>

        {organizations.length > 0 && (
          <Field
            label="Organización"
            hint="Vacío crea un usuario interno de Odessa Oil, con acceso a todas las organizaciones."
            error={errors.organization_id}
          >
            {(props) => (
              <Select
                {...props}
                value={data.organization_id}
                onChange={(event) => setData('organization_id', event.target.value)}
              >
                <option value="">Personal interno de Odessa Oil</option>
                {organizations.map((organization) => (
                  <option key={organization.id} value={organization.id}>
                    {organization.name}
                  </option>
                ))}
              </Select>
            )}
          </Field>
        )}

        <Field label="Roles" hint="Determinan qué módulos y acciones tendrá disponibles." error={errors.roles} required>
          {() => (
            <div className="flex flex-col gap-1.5">
              {roleOptions.map((role) => (
                <label key={role.value} className="flex items-center gap-2 text-[12.5px]">
                  <input
                    type="checkbox"
                    checked={data.roles.includes(role.value)}
                    onChange={(event) =>
                      setData(
                        'roles',
                        event.target.checked
                          ? [...data.roles, role.value]
                          : data.roles.filter((value) => value !== role.value),
                      )
                    }
                    className="size-3.5 rounded-xs accent-[var(--accent)]"
                  />
                  {role.label}
                </label>
              ))}
            </div>
          )}
        </Field>
      </form>
    </DialogContent>
  );
}
