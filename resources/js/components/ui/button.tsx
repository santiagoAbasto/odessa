import { Slot } from '@radix-ui/react-slot';
import { cva, type VariantProps } from 'class-variance-authority';
import { Loader2 } from 'lucide-react';
import { forwardRef, type ButtonHTMLAttributes } from 'react';
import { cn } from '@/lib/cn';

const buttonVariants = cva(
  [
    'inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-xs',
    'text-[13px] font-medium leading-none',
    'transition-colors duration-100',
    'disabled:pointer-events-none disabled:opacity-45',
    '[&_svg]:shrink-0',
  ],
  {
    variants: {
      variant: {
        primary:
          'bg-[var(--accent)] text-[var(--text-inverse)] hover:bg-[var(--accent-hover)] border border-[var(--accent)] hover:border-[var(--accent-hover)]',
        secondary:
          'bg-[var(--surface-raised)] text-[var(--text-primary)] border border-[var(--border-default)] hover:bg-[var(--surface-hover)]',
        ghost:
          'bg-transparent text-[var(--text-secondary)] hover:bg-[var(--surface-hover)] hover:text-[var(--text-primary)] border border-transparent',
        danger:
          'bg-[var(--color-risk-600)] text-white border border-[var(--color-risk-600)] hover:bg-[var(--color-risk-700)] hover:border-[var(--color-risk-700)]',
        link: 'bg-transparent text-[var(--text-accent)] underline-offset-4 hover:underline border-0 p-0 h-auto',
      },
      size: {
        sm: 'h-[var(--spacing-control-sm)] px-2.5 [&_svg]:size-3.5',
        md: 'h-[var(--spacing-control-md)] px-3.5 [&_svg]:size-4',
        lg: 'h-[var(--spacing-control-lg)] px-5 [&_svg]:size-4',
        icon: 'h-[var(--spacing-control-md)] w-[var(--spacing-control-md)] p-0 [&_svg]:size-4',
      },
    },
    defaultVariants: { variant: 'secondary', size: 'md' },
  },
);

export interface ButtonProps
  extends ButtonHTMLAttributes<HTMLButtonElement>,
    VariantProps<typeof buttonVariants> {
  asChild?: boolean;
  /** Muestra spinner y bloquea el control: evita el doble envío. */
  loading?: boolean;
}

export const Button = forwardRef<HTMLButtonElement, ButtonProps>(
  ({ className, variant, size, asChild = false, loading = false, disabled, children, ...props }, ref) => {
    const Comp = asChild ? Slot : 'button';

    return (
      <Comp
        ref={ref}
        className={cn(buttonVariants({ variant, size }), className)}
        disabled={disabled || loading}
        aria-busy={loading || undefined}
        {...props}
      >
        {loading ? (
          <>
            <Loader2 className="animate-spin" aria-hidden />
            {children}
          </>
        ) : (
          children
        )}
      </Comp>
    );
  },
);
Button.displayName = 'Button';

export { buttonVariants };
