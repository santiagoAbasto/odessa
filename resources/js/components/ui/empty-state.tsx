import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';

interface EmptyStateProps {
  icon: LucideIcon;
  title: string;
  description?: string;
  action?: ReactNode;
}

export function EmptyState({ icon: Icon, title, description, action }: EmptyStateProps) {
  return (
    <div className="flex flex-col items-center justify-center px-6 py-16 text-center">
      <span className="mb-4 grid size-11 place-items-center rounded-sm border border-[var(--border-subtle)] bg-[var(--surface-sunken)] text-[var(--text-muted)]">
        <Icon className="size-5" aria-hidden />
      </span>
      <p className="text-[13px] font-medium">{title}</p>
      {description && (
        <p className="mt-1.5 max-w-sm text-[12px] text-[var(--text-secondary)]">{description}</p>
      )}
      {action && <div className="mt-4">{action}</div>}
    </div>
  );
}
