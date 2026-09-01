import { cn } from '@/lib/cn';

/**
 * Marca Odessa Oil.
 *
 * El logotipo original es un mapa de bits con fondo beige opaco y dos colores
 * fijos (granate y azul marino). Se procesó para dejar el fondo transparente,
 * pero los colores no se pueden recolorear sin perder la identidad de la marca.
 *
 * Por eso, sobre superficies oscuras el logotipo va dentro de una placa clara
 * en lugar de invertirse: conserva los colores exactos de marca y garantiza el
 * contraste. Es lo que hacen los manuales de identidad cuando no existe una
 * versión en negativo.
 */

const LOGO_SRC = '/images/odessa-logo.png';
const LOGO_SRC_2X = '/images/odessa-logo@2x.png';

export const BRAND_NAME = 'Odessa Oil';

interface LogoProps {
  className?: string;
  /** Sobre fondo oscuro el logotipo se apoya en una placa clara. */
  onDark?: boolean;
  /** Sidebar contraído: sólo cabe el logotipo reducido. */
  compact?: boolean;
  subtitle?: string;
}

export function Logo({ className, onDark = false, compact = false, subtitle }: LogoProps) {
  const image = (
    <img
      src={LOGO_SRC}
      srcSet={`${LOGO_SRC} 1x, ${LOGO_SRC_2X} 2x`}
      alt={BRAND_NAME}
      width={180}
      height={99}
      className={cn('block w-auto object-contain', compact ? 'h-6' : 'h-7')}
      loading="eager"
      decoding="async"
    />
  );

  const mark = onDark ? (
    <span
      className={cn(
        'inline-flex shrink-0 items-center justify-center rounded-xs bg-white',
        compact ? 'px-1.5 py-1' : 'px-2.5 py-1.5',
      )}
    >
      {image}
    </span>
  ) : (
    image
  );

  if (compact || !subtitle) {
    return <span className={cn('flex items-center', className)}>{mark}</span>;
  }

  return (
    <span className={cn('flex items-center gap-2.5', className)}>
      {mark}
      <span className="flex min-w-0 flex-col leading-none">
        <span className="truncate text-[10px] tracking-[0.14em] uppercase opacity-60">
          {subtitle}
        </span>
      </span>
    </span>
  );
}

/**
 * Marca reducida para usos donde sólo cabe un elemento pequeño
 * (barra superior, favicon inline). Es el mismo logotipo escalado.
 */
export function LogoMark({ className }: { className?: string }) {
  return (
    <img
      src={LOGO_SRC}
      srcSet={`${LOGO_SRC} 1x, ${LOGO_SRC_2X} 2x`}
      alt=""
      width={180}
      height={99}
      aria-hidden="true"
      className={cn('block h-5 w-auto object-contain', className)}
      loading="lazy"
      decoding="async"
    />
  );
}
