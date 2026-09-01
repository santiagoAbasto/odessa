import { differenceInCalendarDays, format, formatDistanceToNowStrict, parseISO } from 'date-fns';
import { es } from 'date-fns/locale';

/** Fechas ISO (`2026-07-30`) o timestamps completos, siempre en es-AR. */
export function formatDate(value: string | null | undefined, pattern = 'dd/MM/yyyy'): string {
  if (!value) return '—';
  try {
    return format(parseISO(value), pattern, { locale: es });
  } catch {
    return '—';
  }
}

export function formatDateTime(value: string | null | undefined): string {
  return formatDate(value, "dd/MM/yyyy HH:mm");
}

export function formatRelative(value: string | null | undefined): string {
  if (!value) return '—';
  try {
    return formatDistanceToNowStrict(parseISO(value), { locale: es, addSuffix: true });
  } catch {
    return '—';
  }
}

/** Días restantes hasta una fecha. Negativo si ya pasó. */
export function daysUntil(value: string | null | undefined): number | null {
  if (!value) return null;
  try {
    return differenceInCalendarDays(parseISO(value), new Date());
  } catch {
    return null;
  }
}

export function formatNumber(value: number): string {
  return new Intl.NumberFormat('es-AR').format(value);
}

/**
 * Texto legible de vencimiento. Se usa junto al badge de estado para que la
 * información no dependa únicamente del color.
 */
export function expiryLabel(value: string | null | undefined): string {
  const days = daysUntil(value);
  if (days === null) return 'Sin vencimiento registrado';
  if (days < 0) return `Vencida hace ${Math.abs(days)} ${Math.abs(days) === 1 ? 'día' : 'días'}`;
  if (days === 0) return 'Vence hoy';
  return `Vence en ${days} ${days === 1 ? 'día' : 'días'}`;
}
