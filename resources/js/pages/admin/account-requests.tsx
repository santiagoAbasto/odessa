import { Head, Link, router, useForm } from '@inertiajs/react';
import { Building2, Check, Inbox, Mail, Phone, X } from 'lucide-react';
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
import { formatDateTime } from '@/lib/format';
import type { Paginated, Tone } from '@/types';

interface RequestRow {
  id: string;
  name: string;
  email: string;
  phone: string | null;
  company: string;
  message: string | null;
  status: 'pending' | 'approved' | 'rejected';
  organization: string | null;
  reviewedBy: string | null;
  reviewedAt: string | null;
  createdAt: string | null;
}

const statusMeta: Record<RequestRow['status'], { label: string; tone: Tone }> = {
  pending: { label: 'Pendiente', tone: 'info' },
  approved: { label: 'Aprobada', tone: 'success' },
  rejected: { label: 'Rechazada', tone: 'danger' },
};

const tabs = [
  { value: 'pending', label: 'Pendientes' },
  { value: 'approved', label: 'Aprobadas' },
  { value: 'rejected', label: 'Rechazadas' },
  { value: 'all', label: 'Todas' },
];

export default function AccountRequests({
  requests,
  filters,
  organizations,
}: {
  requests: Paginated<RequestRow>;
  filters: Record<string, string | undefined>;
  organizations: { id: number; name: string }[];
}) {
  const active = filters.status ?? 'pending';

  return (
    <AppLayout
      title="Solicitudes de acceso"
      description="Alta de cuentas pedidas desde el sitio público. Aprobar registra la decisión; el usuario se crea después desde Usuarios."
      breadcrumbs={[{ label: 'Administración' }, { label: 'Solicitudes de acceso' }]}
    >
      <Head title="Solicitudes de acceso" />

      <div
        role="tablist"
        aria-label="Estado de la solicitud"
        className="mb-4 flex flex-wrap gap-0.5 rounded-sm border border-[var(--border-default)] bg-[var(--surface-raised)] p-0.5"
      >
        {tabs.map((tab) => (
          <Link
            key={tab.value}
            href="/administracion/solicitudes"
            data={{ status: tab.value }}
            preserveScroll
            role="tab"
            aria-selected={active === tab.value}
            className={cn(
              'rounded-xs px-3 py-1.5 text-[12.5px] transition-colors',
              active === tab.value
                ? 'bg-[var(--accent)] font-medium text-[var(--text-inverse)]'
                : 'text-[var(--text-secondary)] hover:bg-[var(--surface-hover)]',
            )}
          >
            {tab.label}
          </Link>
        ))}
      </div>

      {requests.data.length === 0 ? (
        <Panel>
          <EmptyState
            icon={Inbox}
            title="Sin solicitudes"
            description="No hay solicitudes en este estado."
          />
        </Panel>
      ) : (
        <div className="grid gap-3 lg:grid-cols-2">
          {requests.data.map((request) => (
            <Panel key={request.id} className="flex flex-col">
              <div className="flex items-start justify-between gap-3 border-b border-[var(--border-subtle)] px-4 py-3">
                <div className="min-w-0">
                  <p className="truncate text-[13.5px] font-semibold">{request.name}</p>
                  <p className="mt-0.5 text-[11.5px] text-[var(--text-muted)]">
                    Recibida {formatDateTime(request.createdAt)}
                  </p>
                </div>
                <Badge tone={statusMeta[request.status].tone}>{statusMeta[request.status].label}</Badge>
              </div>

              <dl className="flex flex-col gap-2 px-4 py-3 text-[12.5px]">
                <div className="flex items-center gap-2">
                  <Building2 className="size-3.5 shrink-0 text-[var(--text-muted)]" aria-hidden />
                  <dt className="sr-only">Empresa</dt>
                  <dd>{request.company}</dd>
                </div>
                <div className="flex items-center gap-2">
                  <Mail className="size-3.5 shrink-0 text-[var(--text-muted)]" aria-hidden />
                  <dt className="sr-only">Correo</dt>
                  <dd className="truncate">{request.email}</dd>
                </div>
                {request.phone && (
                  <div className="flex items-center gap-2">
                    <Phone className="size-3.5 shrink-0 text-[var(--text-muted)]" aria-hidden />
                    <dt className="sr-only">Teléfono</dt>
                    <dd>{request.phone}</dd>
                  </div>
                )}
              </dl>

              {request.message && (
                <p className="mx-4 mb-3 rounded-xs bg-[var(--surface-sunken)] px-3 py-2 text-[12px] text-[var(--text-secondary)]">
                  {request.message}
                </p>
              )}

              {request.status === 'pending' ? (
                <div className="mt-auto flex justify-end gap-2 border-t border-[var(--border-subtle)] px-4 py-3">
                  <ReviewDialog request={request} organizations={organizations} decision="rejected" />
                  <ReviewDialog request={request} organizations={organizations} decision="approved" />
                </div>
              ) : (
                <p className="mt-auto border-t border-[var(--border-subtle)] px-4 py-3 text-[11.5px] text-[var(--text-muted)]">
                  {request.reviewedBy && `Revisada por ${request.reviewedBy} · `}
                  {formatDateTime(request.reviewedAt)}
                  {request.organization && ` · ${request.organization}`}
                </p>
              )}
            </Panel>
          ))}
        </div>
      )}
    </AppLayout>
  );
}

function ReviewDialog({
  request,
  organizations,
  decision,
}: {
  request: RequestRow;
  organizations: { id: number; name: string }[];
  decision: 'approved' | 'rejected';
}) {
  const [open, setOpen] = useState(false);
  const approving = decision === 'approved';

  const { data, setData, put, processing, errors } = useForm({
    status: decision,
    organization_id: '',
    review_notes: '',
  });

  const submit = (event: FormEvent) => {
    event.preventDefault();
    put(`/administracion/solicitudes/${request.id}`, {
      preserveScroll: true,
      onSuccess: () => setOpen(false),
    });
  };

  return (
    <Dialog open={open} onOpenChange={setOpen}>
      <DialogTrigger asChild>
        <Button variant={approving ? 'primary' : 'secondary'} size="sm">
          {approving ? <Check aria-hidden /> : <X aria-hidden />}
          {approving ? 'Aprobar' : 'Rechazar'}
        </Button>
      </DialogTrigger>

      <DialogContent
        title={approving ? 'Aprobar solicitud' : 'Rechazar solicitud'}
        description={`${request.name} · ${request.company}`}
        footer={
          <>
            <DialogClose asChild>
              <Button variant="secondary" size="sm">
                Cancelar
              </Button>
            </DialogClose>
            <Button
              type="submit"
              form={`review-${request.id}-${decision}`}
              variant={approving ? 'primary' : 'danger'}
              size="sm"
              loading={processing}
            >
              {approving ? 'Aprobar' : 'Rechazar'}
            </Button>
          </>
        }
      >
        <form id={`review-${request.id}-${decision}`} onSubmit={submit} className="flex flex-col gap-4">
          {approving && (
            <Field
              label="Organización"
              hint="El solicitante quedará asociado a esta empresa y sólo verá sus datos."
              error={errors.organization_id}
              required
            >
              {(props) => (
                <Select
                  {...props}
                  value={data.organization_id}
                  onChange={(event) => setData('organization_id', event.target.value)}
                  invalid={Boolean(errors.organization_id)}
                >
                  <option value="">Seleccione una organización</option>
                  {organizations.map((organization) => (
                    <option key={organization.id} value={organization.id}>
                      {organization.name}
                    </option>
                  ))}
                </Select>
              )}
            </Field>
          )}

          <Field label="Nota interna" hint="Opcional. Queda registrada en la auditoría." error={errors.review_notes}>
            {(props) => (
              <Textarea
                {...props}
                value={data.review_notes}
                onChange={(event) => setData('review_notes', event.target.value)}
                maxLength={500}
              />
            )}
          </Field>
        </form>
      </DialogContent>
    </Dialog>
  );
}
