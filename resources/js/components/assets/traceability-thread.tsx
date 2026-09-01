import { Link } from '@inertiajs/react';
import {
  Check,
  ClipboardCheck,
  Eye,
  Inbox,
  MessageSquareQuote,
  PackageCheck,
  Paperclip,
  Play,
  Send,
  Truck,
  Wrench,
  X,
  type LucideIcon,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { EmptyState } from '@/components/ui/empty-state';
import { cn } from '@/lib/cn';
import { formatDateTime } from '@/lib/format';
import type { Tone } from '@/types';

export interface TimelineEvent {
  kind: 'shipment' | 'reception' | 'inspection' | 'document' | 'audit';
  icon: string;
  tone: Tone;
  title: string;
  detail: string | null;
  note?: string | null;
  determinesFitness?: boolean;
  by: string | null;
  ip?: string | null;
  /** Cuándo ocurrió el hecho. */
  at: string | null;
  /** Cuándo quedó registrado en el sistema. */
  recordedAt: string | null;
  href: string | null;
}

const icons: Record<string, LucideIcon> = {
  send: Send,
  check: Check,
  x: X,
  inbox: Inbox,
  play: Play,
  'package-check': PackageCheck,
  truck: Truck,
  'clipboard-check': ClipboardCheck,
  'message-square-quote': MessageSquareQuote,
  wrench: Wrench,
  paperclip: Paperclip,
  eye: Eye,
};

const groups: { value: string; label: string }[] = [
  { value: 'all', label: 'Todo' },
  { value: 'inspection', label: 'Inspecciones' },
  { value: 'reception', label: 'Taller' },
  { value: 'shipment', label: 'Envíos' },
  { value: 'document', label: 'Documentos' },
  { value: 'audit', label: 'Accesos' },
];

/**
 * Hilo de trazabilidad del equipo.
 *
 * Una sola línea de tiempo con todo lo que le pasó a la pieza, sin importar
 * de qué parte del sistema venga. Cada hito responde las tres preguntas que
 * pidió el negocio: quién, qué y cuándo.
 */
export function TraceabilityThread({ events }: { events: TimelineEvent[] }) {
  const [group, setGroup] = useState('all');

  const visible = useMemo(
    () => (group === 'all' ? events : events.filter((event) => event.kind === group)),
    [events, group],
  );

  if (events.length === 0) {
    return (
      <EmptyState
        icon={ClipboardCheck}
        title="Sin movimientos"
        description="Todavía no hay nada registrado sobre este equipo."
      />
    );
  }

  return (
    <div>
      <div className="flex flex-wrap gap-1 border-b border-[var(--border-subtle)] px-3 py-2.5">
        {groups.map((option) => {
          const count =
            option.value === 'all'
              ? events.length
              : events.filter((event) => event.kind === option.value).length;

          if (count === 0 && option.value !== 'all') return null;

          return (
            <button
              key={option.value}
              type="button"
              onClick={() => setGroup(option.value)}
              aria-pressed={group === option.value}
              className={cn(
                'rounded-xs px-2.5 py-1 text-[11.5px] font-medium transition-colors',
                group === option.value
                  ? 'bg-[var(--surface-selected)] text-[var(--text-accent)]'
                  : 'text-[var(--text-secondary)] hover:bg-[var(--surface-hover)]',
              )}
            >
              {option.label}
              <span className="ml-1.5 tabular text-[var(--text-muted)]">{count}</span>
            </button>
          );
        })}
      </div>

      <ol className="px-3 py-3">
        {visible.map((event, index) => {
          const Icon = icons[event.icon] ?? Eye;
          const isLast = index === visible.length - 1;

          return (
            <li key={`${event.kind}-${event.at}-${index}`} className="flex gap-3">
              {/* Rieles del hilo: el icono marca el hito, la línea lo encadena. */}
              <div className="flex flex-col items-center">
                <span
                  className={cn(
                    'flex size-6 shrink-0 items-center justify-center rounded-full border',
                    `border-[var(--tone-${event.tone}-fg)]/30 bg-[var(--tone-${event.tone}-bg)]`,
                  )}
                >
                  <Icon className={`size-3 text-[var(--tone-${event.tone}-fg)]`} aria-hidden />
                </span>
                {!isLast && <span className="w-px flex-1 bg-[var(--border-subtle)]" />}
              </div>

              <div className={cn('min-w-0 flex-1', isLast ? 'pb-0' : 'pb-4')}>
                <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
                  <p className="text-[13px] font-medium">{event.title}</p>

                  {event.determinesFitness && (
                    <Badge tone="info">Fijó el estado</Badge>
                  )}
                </div>

                {event.detail && (
                  <p className="mt-0.5 text-[12px] text-[var(--text-secondary)]">
                    {event.href ? (
                      <Link href={event.href} className="text-[var(--text-accent)] hover:underline">
                        {event.detail}
                      </Link>
                    ) : (
                      event.detail
                    )}
                  </p>
                )}

                {event.note && (
                  <p className="mt-1 border-l-2 border-[var(--border-default)] pl-2 text-[11.5px] text-[var(--text-muted)]">
                    {event.note}
                  </p>
                )}

                <p className="mt-1 flex flex-wrap items-center gap-x-2 text-[11px] text-[var(--text-muted)]">
                  <span className="tabular">{formatDateTime(event.at)}</span>
                  <span aria-hidden>·</span>
                  <span>{event.by ?? 'Sistema'}</span>
                  {event.ip && (
                    <>
                      <span aria-hidden>·</span>
                      <span className="font-mono">{event.ip}</span>
                    </>
                  )}
                  {/*
                    Cuándo se guardó el registro. Sólo se muestra si difiere de
                    cuándo ocurrió: un FMR cargado días después de la inspección
                    es justamente lo que hay que poder auditar.
                  */}
                  {event.recordedAt && event.at && event.recordedAt.slice(0, 10) !== event.at.slice(0, 10) && (
                    <>
                      <span aria-hidden>·</span>
                      <span title="Fecha en que se guardó el registro">
                        registrado {formatDateTime(event.recordedAt)}
                      </span>
                    </>
                  )}
                </p>
              </div>
            </li>
          );
        })}
      </ol>
    </div>
  );
}
