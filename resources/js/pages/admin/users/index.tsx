import { Head, Link, router, useForm } from '@inertiajs/react';
import { Check, CircleSlash, Copy, KeyRound, Pencil, Plus, ShieldCheck, UserCog, Users } from 'lucide-react';
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
  /** Etiquetas para mostrar. */
  roles: string[];
  /** Nombres crudos, para preseleccionar al editar. */
  roleNames: string[];
  /** Permisos sumados por encima del rol (sólo usuarios cliente). */
  extraPermissions: string[];
  lastLoginAt: string | null;
  canEdit: boolean;
  canDelete: boolean;
  canResetAccess: boolean;
}

/** La contraseña temporal recién generada. Llega una sola vez. */
interface TemporaryAccess {
  name: string;
  email: string;
  password: string;
  created: boolean;
}

interface UsersIndexProps {
  users: Paginated<UserRow>;
  filters: Record<string, string | undefined>;
  roleOptions: SelectOption[];
  organizations: { id: string; name: string }[];
  grantablePermissions: SelectOption[];
  /** Lo que cada rol de cliente ya incluye de esa lista. */
  roleGrants: Record<string, string[]>;
  temporaryAccess: TemporaryAccess | null;
  can: { create: boolean; grantPermissions: boolean };
}

/** Lo que necesita el diálogo para ofrecer permisos adicionales. */
interface GrantProps {
  grantable: SelectOption[];
  roleGrants: Record<string, string[]>;
  canGrant: boolean;
}

export default function UsersIndex({
  users,
  filters,
  roleOptions,
  organizations,
  grantablePermissions,
  roleGrants,
  temporaryAccess,
  can,
}: UsersIndexProps) {
  const [creating, setCreating] = useState(false);
  const grants: GrantProps = { grantable: grantablePermissions, roleGrants, canGrant: can.grantPermissions };

  return (
    <AppLayout
      title="Usuarios"
      description="Altas, roles y estado de acceso. Al crear un usuario o restablecer su acceso se genera una contraseña temporal, que el usuario cambia al entrar."
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
            <UserDialog
              roleOptions={roleOptions}
              organizations={organizations}
              grants={grants}
              onDone={() => setCreating(false)}
            />
          </Dialog>
        )
      }
    >
      <Head title="Usuarios" />

      {temporaryAccess && <TemporaryAccessDialog key={temporaryAccess.password} access={temporaryAccess} />}

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

          {/* Sólo el personal interno ve varias organizaciones; un cliente ya
              tiene el listado acotado a la suya y el selector sobraría. */}
          {organizations.length > 0 && (
            <Select
              value={filters.organization ?? ''}
              onChange={(event) =>
                router.get(
                  '/administracion/usuarios',
                  { ...filters, organization: event.target.value || undefined },
                  { preserveState: true, preserveScroll: true, replace: true },
                )
              }
              aria-label="Filtrar por organización"
              className="h-8 w-auto text-[12.5px]"
            >
              <option value="">Todas las organizaciones</option>
              {organizations.map((organization) => (
                <option key={organization.id} value={organization.id}>
                  {organization.name}
                </option>
              ))}
            </Select>
          )}
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
                        {user.extraPermissions.map((permission) => (
                          <Badge key={permission} tone="info" withIcon={false}>
                            + {grantablePermissions.find((p) => p.value === permission)?.label ?? permission}
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
                      <div className="flex items-center justify-end gap-1">
                      {user.canEdit && (
                        <EditUserButton user={user} roleOptions={roleOptions} grants={grants} />
                      )}
                      {user.canResetAccess && (
                        <ConfirmButton
                          variant="ghost"
                          size="sm"
                          title={`Restablecer el acceso de ${user.name}`}
                          description="Se genera una contraseña temporal para pasarle. La actual deja de funcionar y se cierran sus sesiones abiertas."
                          confirmLabel="Generar contraseña"
                          onConfirm={() =>
                            router.post(`/administracion/usuarios/${user.id}/restablecer-acceso`, {}, { preserveScroll: true })
                          }
                        >
                          <KeyRound aria-hidden />
                          Restablecer acceso
                        </ConfirmButton>
                      )}
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
                      </div>
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

/** Abre el diálogo de edición sobre una fila. */
function EditUserButton({
  user,
  roleOptions,
  grants,
}: {
  user: UserRow;
  roleOptions: SelectOption[];
  grants: GrantProps;
}) {
  const [open, setOpen] = useState(false);

  return (
    <Dialog open={open} onOpenChange={setOpen}>
      <DialogTrigger asChild>
        <Button variant="ghost" size="sm">
          <Pencil aria-hidden />
          Editar
        </Button>
      </DialogTrigger>
      {/* Se monta recién al abrir para que el formulario arranque con los
          datos de la fila y no con los de la última que se editó. */}
      {open && (
        <UserDialog user={user} roleOptions={roleOptions} organizations={[]} grants={grants} onDone={() => setOpen(false)} />
      )}
    </Dialog>
  );
}

/**
 * Alta y edición de usuario.
 *
 * Es el mismo formulario porque son los mismos campos. Las dos diferencias
 * van por `user`: al crear se elige organización —después no se cambia, un
 * usuario no se muda de empresa— y al editar aparece el estado de acceso.
 */
function UserDialog({
  user,
  roleOptions,
  organizations,
  grants,
  onDone,
}: {
  user?: UserRow;
  roleOptions: SelectOption[];
  organizations: { id: string; name: string }[];
  grants: GrantProps;
  onDone: () => void;
}) {
  const editing = user !== undefined;

  const { data, setData, post, put, processing, errors, reset } = useForm({
    name: user?.name ?? '',
    email: user?.email ?? '',
    phone: user?.phone ?? '',
    job_title: user?.jobTitle ?? '',
    organization_id: '',
    is_active: user?.isActive ?? true,
    roles: user?.roleNames ?? ([] as string[]),
    extra_permissions: user?.extraPermissions ?? ([] as string[]),
  });

  // Un usuario es cliente si pertenece a una organización. Al crear lo decide
  // la organización elegida; al editar ya viene dado.
  const isClient = editing ? !user.isStaff : data.organization_id !== '';

  const submit = (event: FormEvent) => {
    event.preventDefault();

    const onSuccess = () => {
      reset();
      onDone();
    };

    if (editing) {
      put(`/administracion/usuarios/${user.id}`, { preserveScroll: true, onSuccess });
    } else {
      post('/administracion/usuarios', { preserveScroll: true, onSuccess });
    }
  };

  return (
    <DialogContent
      title={editing ? `Editar a ${user.name}` : 'Nuevo usuario'}
      description={
        editing
          ? 'Los cambios quedan registrados en la auditoría con el usuario que los hizo.'
          : 'Al crearlo se genera una contraseña temporal para pasarle al usuario.'
      }
      footer={
        <>
          <DialogClose asChild>
            <Button variant="secondary" size="sm">
              Cancelar
            </Button>
          </DialogClose>
          <Button type="submit" form="form-usuario" variant="primary" size="sm" loading={processing}>
            <UserCog aria-hidden />
            {editing ? 'Guardar cambios' : 'Crear usuario'}
          </Button>
        </>
      }
    >
      <form id="form-usuario" onSubmit={submit} className="flex flex-col gap-4" noValidate>
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

        {editing && (
          <Field label="Acceso" hint="Un usuario inactivo pierde el acceso en su siguiente petición.">
            {() => (
              <label className="flex items-center gap-2 text-[12.5px]">
                <input
                  type="checkbox"
                  checked={data.is_active}
                  onChange={(event) => setData('is_active', event.target.checked)}
                  className="size-3.5 rounded-xs accent-[var(--accent)]"
                />
                Activo
              </label>
            )}
          </Field>
        )}

        {!editing && organizations.length > 0 && (
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

        {/*
          Permisos puntuales por encima del rol, sólo para usuarios cliente:
          por ejemplo, que uno de consulta cargue envíos, o que sólo el jefe
          autorice reparaciones. Lo que el rol ya da se ve tildado y bloqueado.
        */}
        {grants.canGrant && isClient && (
          <Field
            label="Permisos adicionales"
            hint="Se suman a los del rol, sólo para este usuario."
            error={errors.extra_permissions}
          >
            {() => (
              <div className="flex flex-col gap-1.5">
                {grants.grantable.map((permission) => {
                  const included = data.roles.some((role) => grants.roleGrants[role]?.includes(permission.value));

                  return (
                    <label key={permission.value} className="flex items-center gap-2 text-[12.5px]">
                      <input
                        type="checkbox"
                        disabled={included}
                        checked={included || data.extra_permissions.includes(permission.value)}
                        onChange={(event) =>
                          setData(
                            'extra_permissions',
                            event.target.checked
                              ? [...data.extra_permissions, permission.value]
                              : data.extra_permissions.filter((value) => value !== permission.value),
                          )
                        }
                        className="size-3.5 rounded-xs accent-[var(--accent)]"
                      />
                      {permission.label}
                      {included && <span className="text-[11px] text-[var(--text-muted)]">· incluido en el rol</span>}
                    </label>
                  );
                })}
              </div>
            )}
          </Field>
        )}
      </form>
    </DialogContent>
  );
}

/**
 * La contraseña temporal, lista para pasársela al usuario.
 *
 * Se ve una sola vez: no se guarda en claro. Si se pierde, se vuelve a
 * restablecer el acceso y sale otra.
 */
function TemporaryAccessDialog({ access }: { access: TemporaryAccess }) {
  const [open, setOpen] = useState(true);
  const [copied, setCopied] = useState(false);

  const message = [
    `Hola ${access.name}, ${access.created ? 'ya tiene usuario' : 'le restablecimos el acceso'} en el sistema de Odessa Oil.`,
    `Ingrese en ${window.location.origin}/ingresar`,
    `Usuario: ${access.email}`,
    `Contraseña temporal: ${access.password}`,
    'Al entrar, el sistema le pide que elija una contraseña propia.',
  ].join('\n');

  const copy = () => {
    void navigator.clipboard.writeText(message).then(() => setCopied(true));
  };

  return (
    <Dialog open={open} onOpenChange={setOpen}>
      <DialogContent
        title={access.created ? 'Usuario creado' : 'Acceso restablecido'}
        description="Pásele estos datos al usuario. La contraseña se muestra sólo ahora; al entrar, el sistema le pide que elija una propia."
        footer={
          <>
            <DialogClose asChild>
              <Button variant="secondary" size="sm">
                Cerrar
              </Button>
            </DialogClose>
            <Button variant="primary" size="sm" onClick={copy}>
              {copied ? <Check aria-hidden /> : <Copy aria-hidden />}
              {copied ? 'Copiado' : 'Copiar mensaje'}
            </Button>
          </>
        }
      >
        <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 text-[12.5px]">
          <dt className="text-[var(--text-muted)]">Usuario</dt>
          <dd className="font-medium">{access.email}</dd>
          <dt className="text-[var(--text-muted)]">Contraseña temporal</dt>
          <dd className="font-mono text-[14px] font-semibold select-all">{access.password}</dd>
        </dl>
      </DialogContent>
    </Dialog>
  );
}
