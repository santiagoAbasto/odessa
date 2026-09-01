import { useState, type ReactNode } from 'react';
import { Button, type ButtonProps } from './button';
import { Dialog, DialogClose, DialogContent, DialogTrigger } from './dialog';

interface ConfirmButtonProps extends Omit<ButtonProps, 'onClick'> {
  title: string;
  description: string;
  confirmLabel: string;
  onConfirm: () => void;
  destructive?: boolean;
  children: ReactNode;
}

/**
 * Acción irreversible detrás de una confirmación explícita.
 *
 * En el sistema auditado los hitos operativos se disparaban con un clic
 * directo; aquí liberar o despachar exige confirmar, porque no se deshacen.
 */
export function ConfirmButton({
  title,
  description,
  confirmLabel,
  onConfirm,
  destructive = false,
  children,
  ...buttonProps
}: ConfirmButtonProps) {
  const [open, setOpen] = useState(false);
  const [submitting, setSubmitting] = useState(false);

  return (
    <Dialog open={open} onOpenChange={setOpen}>
      <DialogTrigger asChild>
        <Button {...buttonProps}>{children}</Button>
      </DialogTrigger>
      <DialogContent
        title={title}
        description={description}
        footer={
          <>
            <DialogClose asChild>
              <Button variant="secondary" size="sm">
                Cancelar
              </Button>
            </DialogClose>
            <Button
              variant={destructive ? 'danger' : 'primary'}
              size="sm"
              loading={submitting}
              onClick={() => {
                setSubmitting(true);
                onConfirm();
                setOpen(false);
                setSubmitting(false);
              }}
            >
              {confirmLabel}
            </Button>
          </>
        }
      >
        <p className="text-[13px] text-[var(--text-secondary)]">
          Esta acción queda registrada en la auditoría con su usuario y fecha.
        </p>
      </DialogContent>
    </Dialog>
  );
}
