import type { ReactNode } from 'react';
import { cn } from '@/lib/cn';

/**
 * Superficie base del sistema.
 *
 * Se usa donde hay contenido que agrupar de verdad, no como envoltorio
 * decorativo de cada elemento de la página.
 */
export function Panel({ children, className }: { children: ReactNode; className?: string }) {
  return (
    <section
      className={cn(
        'rounded-sm border border-[var(--border-subtle)] bg-[var(--surface-raised)]',
        'shadow-[var(--shadow-panel)]',
        className,
      )}
    >
      {children}
    </section>
  );
}

interface PanelHeaderProps {
  title: ReactNode;
  description?: ReactNode;
  actions?: ReactNode;
  className?: string;
}

export function PanelHeader({ title, description, actions, className }: PanelHeaderProps) {
  return (
    <header
      className={cn(
        'flex flex-wrap items-center justify-between gap-3 border-b border-[var(--border-subtle)] px-4 py-3',
        className,
      )}
    >
      <div className="min-w-0">
        <h2 className="text-[13px] font-semibold tracking-tight">{title}</h2>
        {description && (
          <p className="mt-0.5 text-[12px] text-[var(--text-secondary)]">{description}</p>
        )}
      </div>
      {actions && <div className="flex shrink-0 items-center gap-2">{actions}</div>}
    </header>
  );
}

export function PanelBody({ children, className }: { children: ReactNode; className?: string }) {
  return <div className={cn('p-4', className)}>{children}</div>;
}
