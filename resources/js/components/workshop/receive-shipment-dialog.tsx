import { useForm } from '@inertiajs/react';
import { Check, PackageCheck, X, Zap } from 'lucide-react';
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
 * Dar recepción a un envío: el paso entre "Pendiente de recepción" y el
 * taller.
 *
 * Es certificar que llegó lo que el cliente declaró, así que se controla
 * equipo por equipo contra lo que pide cada uno. Sólo se confirma con todos
 * tildados; si falta algo o llegó mal, se rechaza el envío con el motivo. El
 * servidor exige lo mismo: esto es la ayuda, no la regla.
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

  const { data, setData, post, processing, errors, reset } = useForm({
    decision: 'authorize' as 'authorize' | 'reject',
    arrived: [] as number[],
    review_notes: '',
    promised_at: '',
  });

  const recibir = data.decision === 'authorize';
  const todos = items.length > 0 && data.arrived.length === items.length;

  const toggle = (id: number, checked: boolean) =>
    setData('arrived', checked ? [...data.arrived, id] : data.arrived.filter((value) => value !== id));

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
        description="Controle que llegó cada equipo declarado. Al confirmar, quedan en Pendientes del taller."
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
              variant={recibir ? 'primary' : 'danger'}
              size="sm"
              loading={processing}
              disabled={recibir && !todos}
            >
              {recibir ? `Dar recepción (${data.arrived.length}/${items.length})` : 'Rechazar envío'}
            </Button>
          </>
        }
      >
        <form id={`form-recepcion-${shipmentId}`} onSubmit={submit} className="flex flex-col gap-4" noValidate>
          <div className="grid grid-cols-2 gap-2">
            {(
              [
                [
                  'authorize',
                  Check,
                  'Llegó: controlar y recibir',
                  'border-[var(--tone-success-fg)] bg-[var(--tone-success-bg)] text-[var(--tone-success-fg)]',
                ],
                [
                  'reject',
                  X,
                  'Falta algo o llegó mal: rechazar',
                  'border-[var(--tone-danger-fg)] bg-[var(--tone-danger-bg)] text-[var(--tone-danger-fg)]',
                ],
              ] as const
            ).map(([value, Icon, label, activeClass]) => (
              <button
                key={value}
                type="button"
                onClick={() => setData('decision', value)}
                aria-pressed={data.decision === value}
                className={cn(
                  'flex items-center gap-2 rounded-sm border px-3 py-2.5 text-left text-[12.5px] transition-colors',
                  data.decision === value ? activeClass : 'border-[var(--border-default)] hover:bg-[var(--surface-hover)]',
                )}
              >
                <Icon className="size-4 shrink-0" aria-hidden />
                {label}
              </button>
            ))}
          </div>

          {recibir && (
            <fieldset className="flex flex-col gap-1.5">
              <legend className="mb-1.5 flex w-full items-center justify-between text-[12px] font-medium">
                <span>Equipos declarados</span>
                <button
                  type="button"
                  className="text-[11.5px] font-normal text-[var(--text-accent)] hover:underline"
                  onClick={() => setData('arrived', todos ? [] : items.map((item) => item.id))}
                >
                  {todos ? 'Destildar todos' : 'Tildar todos'}
                </button>
              </legend>

              {items.map((item) => (
                <label
                  key={item.id}
                  className={cn(
                    'flex items-start gap-2.5 rounded-sm border px-3 py-2.5 text-[12.5px] transition-colors',
                    data.arrived.includes(item.id)
                      ? 'border-[var(--tone-success-fg)]/40 bg-[var(--tone-success-bg)]'
                      : 'border-[var(--border-default)]',
                  )}
                >
                  <input
                    type="checkbox"
                    checked={data.arrived.includes(item.id)}
                    onChange={(event) => toggle(item.id, event.target.checked)}
                    className="mt-0.5 size-3.5 rounded-xs accent-[var(--accent)]"
                  />
                  <span className="min-w-0 flex-1">
                    <span className="flex items-center gap-1.5 font-medium">
                      {item.isUrgent && <Zap className="size-3.5 text-[var(--tone-danger-fg)]" aria-label="Urgente" />}
                      {item.name}
                      <span className="font-mono text-[11px] font-normal text-[var(--text-muted)]">
                        {item.serial ?? 'sin serie'}
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
              ))}

              {errors.arrived && (
                <p role="alert" className="text-[11.5px] text-[var(--tone-danger-fg)]">
                  {errors.arrived}
                </p>
              )}
            </fieldset>
          )}

          {recibir && (
            <Field
              label="Fecha comprometida de entrega"
              hint="Opcional. Se aplica a todos los equipos del envío."
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
            label={recibir ? 'Observaciones de cómo llegó' : 'Motivo del rechazo'}
            hint={recibir ? 'Opcional. Por ejemplo: embalaje, golpes, documentación.' : 'El cliente ve este motivo.'}
            error={errors.review_notes}
            required={!recibir}
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
