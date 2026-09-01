import { AlertTriangle, CheckCircle2, CircleDashed, Info, XCircle } from 'lucide-react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/cn';
import type { Tone } from '@/types';

const toneStyles: Record<Tone, string> = {
  success: 'bg-[var(--tone-success-bg)] text-[var(--tone-success-fg)]',
  warning: 'bg-[var(--tone-warning-bg)] text-[var(--tone-warning-fg)]',
  danger: 'bg-[var(--tone-danger-bg)] text-[var(--tone-danger-fg)]',
  info: 'bg-[var(--tone-info-bg)] text-[var(--tone-info-fg)]',
  neutral: 'bg-[var(--tone-neutral-bg)] text-[var(--tone-neutral-fg)]',
};

const toneIcons: Record<Tone, typeof CheckCircle2> = {
  success: CheckCircle2,
  warning: AlertTriangle,
  danger: XCircle,
  info: Info,
  neutral: CircleDashed,
};

interface BadgeProps {
  tone?: Tone;
  children: ReactNode;
  className?: string;
  /** El icono acompaña al color para no depender sólo del matiz (WCAG 1.4.1). */
  withIcon?: boolean;
}

export function Badge({ tone = 'neutral', children, className, withIcon = true }: BadgeProps) {
  const Icon = toneIcons[tone];

  return (
    <span
      className={cn(
        'inline-flex items-center gap-1.5 rounded-xs px-2 py-1 text-[11px] font-medium leading-none',
        toneStyles[tone],
        className,
      )}
    >
      {withIcon && <Icon className="size-3" aria-hidden />}
      {children}
    </span>
  );
}
