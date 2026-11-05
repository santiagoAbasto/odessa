import { useForm } from '@inertiajs/react';
import { useState, type FormEvent, type ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import { Dialog, DialogClose, DialogContent, DialogTrigger } from '@/components/ui/dialog';
import { Field } from '@/components/ui/field';
import { Input, Select } from '@/components/ui/input';

/** Operación que certifica y que todavía no tiene ninguna certificación en el equipo. */
export interface ExternalServiceOption {
  id: number;
  code: string;
  name: string;
}

/** Certificación ya cargada a mano, que se puede editar. */
export interface ExternalCertificationValues {
  serviceId: number;
  code: string;
  name: string;
  certifiedBy: string;
  certificateNumber: string | null;
  /** Cuándo se certificó. */
  inspectedAt: string | null;
  expiresAt: string | null;
}

/**
 * Carga, a mano en la ficha, la certificación que el equipo ya traía de OTRA
 * empresa.
 *
 * No crea un informe —Odessa no certificó nada—: es un dato de la ficha, con su
 * operación, la empresa que certificó y las fechas, para que el cliente tenga
 * trazabilidad de vencimientos. Queda en la auditoría como carga manual.
 * Con `current` edita la de esa operación; sin él, ofrece las operaciones
 * que todavía no tienen certificación.
 */
export function ExternalCertificationDialog({
  assetId,
  services,
  current,
  children,
}: {
  assetId: string;
  services: ExternalServiceOption[];
  current?: ExternalCertificationValues;
  /** El botón que abre el formulario. */
  children: ReactNode;
}) {
  const [open, setOpen] = useState(false);
  const { data, setData, post, processing, errors, reset, clearErrors } = useForm({
    service_id: current ? String(current.serviceId) : '',
    certified_by: current?.certifiedBy ?? '',
    certificate_number: current?.certificateNumber ?? '',
    certified_at: current?.inspectedAt ?? '',
    expires_at: current?.expiresAt ?? '',
  });
  const formId = `form-cert-externa-${assetId}-${current?.serviceId ?? 'nueva'}`;
  const hoy = new Date().toISOString().slice(0, 10);

  const submit = (event: FormEvent) => {
    event.preventDefault();
    post(`/activos/${assetId}/certificaciones-externas`, {
      preserveScroll: true,
      onSuccess: () => {
        if (!current) reset();
        setOpen(false);
      },
    });
  };

  return (
    <Dialog
      open={open}
      onOpenChange={(next) => {
        setOpen(next);
        if (!next) clearErrors();
      }}
    >
      <DialogTrigger asChild>{children}</DialogTrigger>

      <DialogContent
        title={current ? `Editar la certificación ${current.code}` : 'Cargar certificación de otra empresa'}
        description="La certificó otra empresa, no Odessa: no se crea un informe. Se registra en la ficha con sus vigencias y queda en la auditoría como carga manual."
        footer={
          <>
            <DialogClose asChild>
              <Button variant="secondary" size="sm">
                Cancelar
              </Button>
            </DialogClose>
            <Button type="submit" form={formId} variant="primary" size="sm" loading={processing}>
              {current ? 'Guardar cambios' : 'Cargar certificación'}
            </Button>
          </>
        }
      >
        <form id={formId} onSubmit={submit} className="flex flex-col gap-4" noValidate>
          <Field label="Operación" error={errors.service_id} required>
            {(props) => (
              <Select
                {...props}
                value={data.service_id}
                disabled={Boolean(current)}
                onChange={(event) => setData('service_id', event.target.value)}
                invalid={Boolean(errors.service_id)}
              >
                {!current && <option value="">Elegir operación…</option>}
                {(current ? [{ id: current.serviceId, code: current.code, name: current.name }] : services).map(
                  (service) => (
                    <option key={service.id} value={service.id}>
                      {service.code} · {service.name}
                    </option>
                  ),
                )}
              </Select>
            )}
          </Field>

          <Field label="Empresa que certificó" error={errors.certified_by} required>
            {(props) => (
              <Input
                {...props}
                value={data.certified_by}
                maxLength={160}
                onChange={(event) => setData('certified_by', event.target.value)}
                invalid={Boolean(errors.certified_by)}
              />
            )}
          </Field>

          <Field label="Número de certificado" hint="Si lo tiene." error={errors.certificate_number}>
            {(props) => (
              <Input
                {...props}
                value={data.certificate_number}
                maxLength={120}
                onChange={(event) => setData('certificate_number', event.target.value)}
                invalid={Boolean(errors.certificate_number)}
              />
            )}
          </Field>

          <div className="grid gap-4 sm:grid-cols-2">
            <Field label="Fecha de certificación" error={errors.certified_at} required>
              {(props) => (
                <Input
                  {...props}
                  type="date"
                  max={hoy}
                  value={data.certified_at}
                  onChange={(event) => setData('certified_at', event.target.value)}
                  invalid={Boolean(errors.certified_at)}
                />
              )}
            </Field>

            <Field label="Vence" error={errors.expires_at} required>
              {(props) => (
                <Input
                  {...props}
                  type="date"
                  value={data.expires_at}
                  onChange={(event) => setData('expires_at', event.target.value)}
                  invalid={Boolean(errors.expires_at)}
                />
              )}
            </Field>
          </div>
        </form>
      </DialogContent>
    </Dialog>
  );
}
