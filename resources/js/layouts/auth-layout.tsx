import { Link } from '@inertiajs/react';
import { FileCheck2, GaugeCircle, History, ShieldCheck } from 'lucide-react';
import type { ReactNode } from 'react';
import { BRAND_NAME, Logo } from '@/components/brand/logo';

const highlights = [
  { icon: GaugeCircle, title: 'Estado en tiempo real', text: 'Aptitud y vencimiento de cada pieza, sin pedir informes.' },
  { icon: History, title: 'Historial completo', text: 'Toda la trazabilidad del activo desde su primer ingreso.' },
  { icon: FileCheck2, title: 'Documentación al instante', text: 'Certificados e informes descargables cuando los necesite.' },
  { icon: ShieldCheck, title: 'Datos aislados', text: 'Cada organización ve únicamente su propio equipamiento.' },
];

/**
 * Marco de las pantallas de sesión.
 *
 * Panel izquierdo de contexto (oculto en móvil, donde sólo estorba) y
 * formulario a la derecha con ancho de lectura cómodo.
 */
export default function AuthLayout({
  title,
  subtitle,
  children,
}: {
  title: string;
  subtitle?: string;
  children: ReactNode;
}) {
  return (
    <div className="grid min-h-screen lg:grid-cols-[1.05fr_1fr]">
      {/* --- Contexto de marca --- */}
      <aside className="relative hidden flex-col justify-between overflow-hidden bg-[var(--color-steel-950)] p-10 text-white lg:flex">
        <div className="blueprint-grid pointer-events-none absolute inset-0 opacity-[0.14]" aria-hidden />

        <Link href="/" className="relative" aria-label="Odessa Oil, inicio">
          <Logo onDark subtitle="Inspección técnica" />
        </Link>

        <div className="relative">
          <h2 className="max-w-md text-[30px] leading-[1.15] font-semibold tracking-tight">
            Trazabilidad completa del equipamiento de presión.
          </h2>
          <p className="mt-3 max-w-md text-[13px] leading-relaxed text-white/60">
            Cada pieza conserva su historial de inspecciones, su dictamen vigente y sus documentos.
          </p>

          <ul className="mt-8 grid max-w-lg grid-cols-2 gap-x-6 gap-y-5">
            {highlights.map((item) => (
              <li key={item.title}>
                <item.icon className="mb-2 size-4 text-white/70" aria-hidden />
                <p className="text-[12.5px] font-medium">{item.title}</p>
                <p className="mt-1 text-[11.5px] leading-relaxed text-white/50">{item.text}</p>
              </li>
            ))}
          </ul>
        </div>

        <p className="relative text-[11px] text-white/35">
          © {new Date().getFullYear()} {BRAND_NAME}
        </p>
      </aside>

      {/* --- Formulario --- */}
      <main className="flex items-center justify-center bg-[var(--surface-raised)] px-5 py-12">
        <div className="w-full max-w-sm">
          <Link href="/" className="mb-8 inline-block text-[var(--text-primary)] lg:hidden">
            <Logo />
          </Link>

          <h1 className="text-[24px] leading-tight font-semibold tracking-tight">{title}</h1>
          {subtitle && (
            <p className="mt-1.5 text-[13px] text-[var(--text-secondary)]">{subtitle}</p>
          )}

          <div className="mt-7">{children}</div>
        </div>
      </main>
    </div>
  );
}
