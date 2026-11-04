import { useForm } from '@inertiajs/react';
import { Pencil, Trash2 } from 'lucide-react';
import { useState, type FormEvent } from 'react';
import { Button } from '@/components/ui/button';
import { Dialog, DialogClose, DialogContent, DialogTrigger } from '@/components/ui/dialog';
import { Field } from '@/components/ui/field';
import { Input, Select, Textarea } from '@/components/ui/input';

export interface EditableReport {
  id: string;
  number: string;
  inspectedAt: string | null;
  serviceId: string;
  equipmentLabel: string | null;
  notes: string | null;
  status: string;
}

/**
 * Corrige los datos del informe: número, fecha, operación, equipo y notas.
 *
 * Sirve también para un informe ya final (sólo el taller): ahí la fecha y la
 * operación entran en el cálculo del estado de las piezas, y el servidor las
 * recalcula. Cada cambio queda en la auditoría con su antes y su después.
 */
export function EditReportDialog({
  report,
  serviceOptions,
}: {
  report: EditableReport;
  serviceOptions: { value: string; label: string }[];
}) {
  const [open, setOpen] = useState(false);
  const initial = {
    number: report.number,
    inspected_at: report.inspectedAt ?? '',
    service_id: report.serviceId,
    equipment_label: report.equipmentLabel ?? '',
    notes: report.notes ?? '',
  };
  const { data, setData, put, processing, errors, clearErrors } = useForm(initial);

  const finalizado = report.status === 'final';

  const submit = (event: FormEvent) => {
    event.preventDefault();
    put(`/informes/${report.id}`, { preserveScroll: true, onSuccess: () => setOpen(false) });
  };

  return (
    <Dialog
      open={open}
      onOpenChange={(next) => {
        // Cada apertura parte de lo guardado, no de un intento anterior a medias.
        if (next) {
          setData(initial);
          clearErrors();
        }
        setOpen(next);
      }}
    >
      <DialogTrigger asChild>
        <Button variant="secondary" size="sm">
          <Pencil aria-hidden />
          Editar datos
        </Button>
      </DialogTrigger>

      <DialogContent
        title={`Editar el informe N° ${report.number}`}
        description={
          finalizado
            ? 'Este informe ya está finalizado: al guardar se recalcula el estado de sus piezas y el cambio queda en la auditoría. Los vencimientos ya cargados no se mueven con la fecha; corríjalos en el dictamen de cada pieza.'
            : 'Corrija lo que haga falta. El cambio queda en la auditoría.'
        }
        footer={
          <>
            <DialogClose asChild>
              <Button variant="secondary" size="sm">
                Cancelar
              </Button>
            </DialogClose>
            <Button type="submit" form="form-editar-informe" variant="primary" size="sm" loading={processing}>
              Guardar cambios
            </Button>
          </>
        }
      >
        <form id="form-editar-informe" onSubmit={submit} className="flex flex-col gap-4" noValidate>
          <div className="grid gap-4 sm:grid-cols-2">
            <Field label="Número de informe" error={errors.number} required>
              {(props) => (
                <Input
                  {...props}
                  value={data.number}
                  maxLength={30}
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
                  value={data.inspected_at}
                  max={new Date().toISOString().slice(0, 10)}
                  onChange={(event) => setData('inspected_at', event.target.value)}
                  invalid={Boolean(errors.inspected_at)}
                />
              )}
            </Field>
          </div>

          <Field label="Operación" error={errors.service_id}>
            {(props) => (
              <Select
                {...props}
                value={data.service_id}
                onChange={(event) => setData('service_id', event.target.value)}
                invalid={Boolean(errors.service_id)}
              >
                <option value="">Sin operación</option>
                {serviceOptions.map((option) => (
                  <option key={option.value} value={option.value}>
                    {option.label}
                  </option>
                ))}
              </Select>
            )}
          </Field>

          <Field label="Equipo" error={errors.equipment_label}>
            {(props) => (
              <Input
                {...props}
                value={data.equipment_label}
                maxLength={180}
                onChange={(event) => setData('equipment_label', event.target.value)}
                invalid={Boolean(errors.equipment_label)}
              />
            )}
          </Field>

          <Field label="Notas" error={errors.notes}>
            {(props) => (
              <Textarea
                {...props}
                value={data.notes}
                rows={3}
                maxLength={2000}
                onChange={(event) => setData('notes', event.target.value)}
              />
            )}
          </Field>
        </form>
      </DialogContent>
    </Dialog>
  );
}

/**
 * Elimina un informe cargado por error, con motivo obligatorio.
 *
 * Es una baja lógica: el informe y su rastro quedan en la auditoría. Si era
 * final, las piezas que dictaminaba se recalculan sin él.
 */
export function DeleteReportDialog({ report }: { report: EditableReport }) {
  const [open, setOpen] = useState(false);
  const { data, setData, delete: destroy, processing, errors, reset, clearErrors } = useForm({ reason: '' });

  const finalizado = report.status === 'final';

  const submit = (event: FormEvent) => {
    event.preventDefault();
    destroy(`/informes/${report.id}`, { onSuccess: () => setOpen(false) });
  };

  return (
    <Dialog
      open={open}
      onOpenChange={(next) => {
        if (next) {
          reset();
          clearErrors();
        }
        setOpen(next);
      }}
    >
      <DialogTrigger asChild>
        <Button variant="danger" size="sm">
          <Trash2 aria-hidden />
          Eliminar
        </Button>
      </DialogTrigger>

      <DialogContent
        title={`Eliminar el informe N° ${report.number}`}
        description={
          finalizado
            ? 'Este informe ya está finalizado: al eliminarlo, sus piezas dejan de contar este dictamen y se recalcula su estado. Queda registrado en la auditoría.'
            : 'El informe deja de mostrarse. Queda registrado en la auditoría.'
        }
        footer={
          <>
            <DialogClose asChild>
              <Button variant="secondary" size="sm">
                Cancelar
              </Button>
            </DialogClose>
            <Button type="submit" form="form-eliminar-informe" variant="danger" size="sm" loading={processing}>
              Eliminar informe
            </Button>
          </>
        }
      >
        <form id="form-eliminar-informe" onSubmit={submit} className="flex flex-col gap-4" noValidate>
          <Field
            label="Motivo"
            hint="Qué pasó: por ejemplo, «se cargó sobre el equipo equivocado»."
            error={errors.reason}
            required
          >
            {(props) => (
              <Textarea
                {...props}
                value={data.reason}
                rows={3}
                maxLength={500}
                onChange={(event) => setData('reason', event.target.value)}
                invalid={Boolean(errors.reason)}
              />
            )}
          </Field>
        </form>
      </DialogContent>
    </Dialog>
  );
}
