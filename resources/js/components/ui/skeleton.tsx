import { cn } from '@/lib/cn';

export function Skeleton({ className }: { className?: string }) {
  return (
    <div
      className={cn('animate-pulse rounded-xs bg-[var(--surface-hover)]', className)}
      aria-hidden
    />
  );
}

/** Esqueleto de tabla: mismas alturas que las filas reales, sin saltos de layout. */
export function TableSkeleton({ rows = 6, columns = 5 }: { rows?: number; columns?: number }) {
  return (
    <div className="divide-y divide-[var(--border-subtle)]" aria-hidden>
      {Array.from({ length: rows }).map((_, rowIndex) => (
        <div
          key={rowIndex}
          className="flex h-[var(--row-height)] items-center gap-4 px-4"
        >
          {Array.from({ length: columns }).map((_, colIndex) => (
            <Skeleton
              key={colIndex}
              className={cn('h-3', colIndex === 0 ? 'w-[28%]' : 'w-[12%]')}
            />
          ))}
        </div>
      ))}
    </div>
  );
}
