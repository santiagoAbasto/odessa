import { useForm } from '@inertiajs/react';
import { PackageCheck, Zap } from 'lucide-react';
import { useState, type FormEvent } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogClose, DialogContent, DialogTrigger } from '@/components/ui/dialog';
import { Field } from '@/components/ui/field';
import { Input, Textarea } from '@/components/ui/input';
import { cn } from '@/lib/cn';

export interface ArrivingItem {
  id: number;
  name: string;
  serial: string | null;
  isUrgent?: boolean;
  /** Lo que pide el cliente para este equipo. */
  operations: string[];
  specialOperation: string | null;
}

/**
 * Dar recepción a un envío: el paso entre "Pendiente de recepción" y el taller.
 *
 * Se asume que llegó lo que el cliente declaró. El taller sólo destilda lo que
 * NO llegó e indica el motivo: eso no entra al taller y se le avisa al operador
 * por correo. Lo que sí llegó ingresa y queda en Pendientes. Si no llegó nada,
 * el envío queda rechazado.
 */
export function ReceiveShipmentDialog({
  shipmentId,
  reference,
  items,
}: {
  shipmentId: string;
  reference: string;
  items: ArrivingItem[];
}) {
  const [open, setOpen] = useState(false);
  const allIds = items.map((item) => item.id);

  const { data, setData, post, processing, errors, reset } = useForm({
    // Siempre es una recepción; el rechazo total es "no llegó ninguno".
    decision: 'authorize' as const,
    arrived: allIds,
    reasons: {} as Record<number, string>,
    review_notes: '',
    promised_at: '',
  });

  const arrivedCount = data.arrived.length;
  const missingCount = items.length - arrivedCount;
  const reasonError = (id: number) => (errors as Record<string, string | undefined>)[`reasons.${id}`];

  const toggle = (id: number, arrived: boolean) =>
    setData('arrived', arrived ? [...data.arrived, id] : data.arrived.filter((value) => value !== id));

  const setReason = (id: number, value: string) => setData('reasons', { ...data.reasons, [id]: value });

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

  const buttonLabel =
    arrivedCount === 0
      ? 'Rechazar envío (no llegó nada)'
      : missingCount === 0
        ? `Dar recepción (${arrivedCount})`
        : `Recibir ${arrivedCount} · ${missingCount} no llegó${missingCount === 1 ? '' : 'n'}`;

  return (
    <Dialog
      open={open}
      onOpenChange={(value) => {
        setOpen(value);
        if (!value) reset();
      }}
    >
      <DialogTrigger asChild>
        <Button variant="primary" size="sm">
          <PackageCheck aria-hidden />
          Dar recepción
        </Button>
      </DialogTrigger>

      <DialogContent
        title={`Dar recepción · ${reference}`}
        description="Se asume que llegó lo declarado. Destilde lo que no llegó e indique el motivo: no entra al taller y se le avisa al operador."
        className="max-w-2xl"
        footer={
          <>
            <DialogClose asChild>
              <Button variant="secondary" size="sm">
                Cancelar
              </Button>
            </DialogClose>
            <Button
              type="submit"
              form={`form-recepcion-${shipmentId}`}
              variant={arrivedCount === 0 ? 'danger' : 'primary'}
              size="sm"
              loading={processing}
            >
              {buttonLabel}
            </Button>
          </>
        }
      >
        <form id={`form-recepcion-${shipmentId}`} onSubmit={submit} className="flex flex-col gap-4" noValidate>
          <fieldset className="flex flex-col gap-1.5">
            <legend className="mb-1.5 text-[12px] font-medium">Equipos declarados</legend>

            {items.map((item) => {
              const arrived = data.arrived.includes(item.id);

              return (
                <div
                  key={item.id}
                  className={cn(
                    'rounded-sm border px-3 py-2.5 text-[12.5px] transition-colors',
                    arrived
                      ? 'border-[var(--tone-success-fg)]/40 bg-[var(--tone-success-bg)]'
                      : 'border-[var(--tone-danger-fg)]/40 bg-[var(--tone-danger-bg)]',
                  )}
                >
                  <label className="flex items-start gap-2.5">
                    <input
                      type="checkbox"
                      checked={arrived}
                      onChange={(event) => toggle(item.id, event.target.checked)}
                      className="mt-0.5 size-3.5 rounded-xs accent-[var(--accent)]"
                      aria-label={`Llegó ${item.name}`}
                    />
                    <span className="min-w-0 flex-1">
                      <span className="flex items-center gap-1.5 font-medium">
                        {item.isUrgent && <Zap className="size-3.5 text-[var(--tone-danger-fg)]" aria-label="Urgente" />}
                        {item.name}
                        <span className="font-mono text-[11px] font-normal text-[var(--text-muted)]">
                          {item.serial ?? 'sin serie'}
                        </span>
                        <span
                          className={cn(
                            'ml-auto shrink-0 text-[11px] font-medium',
                            arrived ? 'text-[var(--tone-success-fg)]' : 'text-[var(--tone-danger-fg)]',
                          )}
                        >
                          {arrived ? 'Llegó' : 'No llegó'}
                        </span>
                      </span>
                      {(item.operations.length > 0 || item.specialOperation) && (
                        <span className="mt-1 flex flex-wrap items-center gap-1">
                          {item.operations.map((operation) => (
                            <Badge key={operation} tone="info">
                              {operation}
                            </Badge>
                          ))}
                          {item.specialOperation && (
                            <span className="text-[11.5px] text-[var(--text-secondary)]">
                              <span className="font-medium text-[var(--tone-warning-fg)]">Especial:</span>{' '}
                              {item.specialOperation}
                            </span>
                          )}
                        </span>
                      )}
                    </span>
                  </label>

                  {!arrived && (
                    <div className="mt-2 pl-6">
                      <Field label="Por qué no llegó" error={reasonError(item.id)} required>
                        {(props) => (
                          <Textarea
                            {...props}
                            value={data.reasons[item.id] ?? ''}
                            maxLength={500}
                            rows={2}
                            placeholder="Es lo que se le informa al operador."
                            onChange={(event) => setReason(item.id, event.target.value)}
                            invalid={Boolean(reasonError(item.id))}
                          />
                        )}
                      </Field>
                    </div>
                  )}
                </div>
              );
            })}
          </fieldset>

          {arrivedCount > 0 && (
            <Field
              label="Fecha comprometida de entrega"
              hint="Opcional. Se aplica a los equipos que ingresan."
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
          )}

          <Field
            label="Observaciones"
            hint="Opcional. Por ejemplo: embalaje, golpes, documentación."
            error={errors.review_notes}
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
