import { forwardRef, type InputHTMLAttributes, type ReactNode } from 'react';
import { cn } from '@/lib/cn';

export interface InputProps extends InputHTMLAttributes<HTMLInputElement> {
  invalid?: boolean;
  icon?: ReactNode;
}

export const Input = forwardRef<HTMLInputElement, InputProps>(
  ({ className, invalid, icon, ...props }, ref) => {
    const control = (
      <input
        ref={ref}
        aria-invalid={invalid || undefined}
        className={cn(
          'h-[var(--spacing-control-md)] w-full rounded-xs border bg-[var(--surface-raised)] px-2.5',
          'text-[13px] text-[var(--text-primary)] placeholder:text-[var(--text-muted)]',
          'transition-colors outline-none',
          'disabled:cursor-not-allowed disabled:bg-[var(--surface-sunken)] disabled:opacity-60',
          invalid
            ? 'border-[var(--color-risk-600)]'
            : 'border-[var(--border-default)] focus:border-[var(--focus-ring)]',
          icon && 'pl-8',
          className,
        )}
        {...props}
      />
    );

    if (!icon) return control;

    return (
      <span className="relative block">
        <span className="pointer-events-none absolute top-1/2 left-2.5 -translate-y-1/2 text-[var(--text-muted)] [&_svg]:size-3.5">
          {icon}
        </span>
        {control}
      </span>
    );
  },
);
Input.displayName = 'Input';

export const Textarea = forwardRef<
  HTMLTextAreaElement,
  React.TextareaHTMLAttributes<HTMLTextAreaElement> & { invalid?: boolean }
>(({ className, invalid, ...props }, ref) => (
  <textarea
    ref={ref}
    aria-invalid={invalid || undefined}
    className={cn(
      'min-h-24 w-full rounded-xs border bg-[var(--surface-raised)] px-2.5 py-2',
      'text-[13px] text-[var(--text-primary)] placeholder:text-[var(--text-muted)]',
      'transition-colors outline-none',
      invalid
        ? 'border-[var(--color-risk-600)]'
        : 'border-[var(--border-default)] focus:border-[var(--focus-ring)]',
      className,
    )}
    {...props}
  />
));
Textarea.displayName = 'Textarea';

export const Select = forwardRef<
  HTMLSelectElement,
  React.SelectHTMLAttributes<HTMLSelectElement> & { invalid?: boolean }
>(({ className, invalid, children, ...props }, ref) => (
  <select
    ref={ref}
    aria-invalid={invalid || undefined}
    className={cn(
      'h-[var(--spacing-control-md)] w-full appearance-none rounded-xs border bg-[var(--surface-raised)]',
      'px-2.5 pr-7 text-[13px] text-[var(--text-primary)] transition-colors outline-none',
      "bg-[url(\"data:image/svg+xml;charset=utf-8,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%23888' stroke-width='2.5' stroke-linecap='round'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E\")] bg-[length:12px] bg-[right_0.6rem_center] bg-no-repeat",
      invalid
        ? 'border-[var(--color-risk-600)]'
        : 'border-[var(--border-default)] focus:border-[var(--focus-ring)]',
      className,
    )}
    {...props}
  >
    {children}
  </select>
));
Select.displayName = 'Select';
