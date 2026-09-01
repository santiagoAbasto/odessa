import { useId, type ReactNode } from 'react';
import { AlertCircle } from 'lucide-react';
import { cn } from '@/lib/cn';

interface FieldProps {
  label: string;
  /** Ayuda contextual bajo la etiqueta, antes de que el usuario se equivoque. */
  hint?: string;
  error?: string;
  required?: boolean;
  className?: string;
  children: (props: {
    id: string;
    'aria-describedby': string | undefined;
    'aria-invalid': boolean | undefined;
    required: boolean;
  }) => ReactNode;
}

/**
 * Envoltorio accesible de campo.
 *
 * Enlaza etiqueta, ayuda y error con el control mediante ids generados, para
 * que un lector de pantalla anuncie el error junto al campo (WCAG 3.3.1).
 */
export function Field({ label, hint, error, required = false, className, children }: FieldProps) {
  const id = useId();
  const hintId = hint ? `${id}-hint` : undefined;
  const errorId = error ? `${id}-error` : undefined;
  const describedBy = [hintId, errorId].filter(Boolean).join(' ') || undefined;

  return (
    <div className={cn('flex flex-col gap-1.5', className)}>
      <label htmlFor={id} className="text-[12px] font-medium text-[var(--text-primary)]">
        {label}
        {required && (
          <span className="ml-1 text-[var(--color-risk-600)]" aria-hidden>
            *
          </span>
        )}
        {required && <span className="sr-only">(obligatorio)</span>}
      </label>

      {hint && (
        <p id={hintId} className="text-[11px] text-[var(--text-secondary)]">
          {hint}
        </p>
      )}

      {children({
        id,
        'aria-describedby': describedBy,
        'aria-invalid': error ? true : undefined,
        required,
      })}

      {error && (
        <p
          id={errorId}
          role="alert"
          className="flex items-start gap-1.5 text-[11px] text-[var(--color-risk-600)]"
        >
          <AlertCircle className="mt-px size-3 shrink-0" aria-hidden />
          {error}
        </p>
      )}
    </div>
  );
}
