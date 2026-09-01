import { Link } from '@inertiajs/react';
import { Menu, X } from 'lucide-react';
import { useState, type ReactNode } from 'react';
import { BRAND_NAME, Logo } from '@/components/brand/logo';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/cn';

const links = [
  { label: 'Servicios', href: '/servicios' },
  { label: 'Solicitar acceso', href: '/solicitar-acceso' },
];

/** Cabecera y pie del sitio abierto. Sin datos de clientes, sin sesión. */
export default function PublicLayout({ children }: { children: ReactNode }) {
  const [open, setOpen] = useState(false);

  return (
    <div className="flex min-h-screen flex-col bg-[var(--surface-raised)]">
      <a href="#contenido" className="skip-link">
        Saltar al contenido
      </a>

      <header className="sticky top-0 z-30 border-b border-[var(--border-subtle)] bg-[var(--surface-raised)]/95 backdrop-blur">
        <div className="mx-auto flex h-16 w-full max-w-6xl items-center gap-6 px-5">
          <Link href="/" className="text-[var(--text-primary)]" aria-label="Odessa Oil, inicio">
            <Logo />
          </Link>

          <nav aria-label="Navegación del sitio" className="ml-auto hidden items-center gap-1 md:flex">
            {links.map((link) => (
              <Link
                key={link.href}
                href={link.href}
                className="rounded-xs px-3 py-2 text-[13px] text-[var(--text-secondary)] hover:bg-[var(--surface-hover)] hover:text-[var(--text-primary)]"
              >
                {link.label}
              </Link>
            ))}
            <Button asChild variant="primary" size="sm" className="ml-2">
              <Link href="/ingresar">Ingresar</Link>
            </Button>
          </nav>

          <Button
            variant="ghost"
            size="icon"
            className="ml-auto md:hidden"
            onClick={() => setOpen((value) => !value)}
            aria-expanded={open}
            aria-controls="menu-publico"
            aria-label={open ? 'Cerrar menú' : 'Abrir menú'}
          >
            {open ? <X aria-hidden /> : <Menu aria-hidden />}
          </Button>
        </div>

        <div
          id="menu-publico"
          className={cn('border-t border-[var(--border-subtle)] md:hidden', !open && 'hidden')}
        >
          <nav aria-label="Navegación del sitio" className="flex flex-col p-3">
            {links.map((link) => (
              <Link
                key={link.href}
                href={link.href}
                className="rounded-xs px-3 py-2.5 text-[13px] hover:bg-[var(--surface-hover)]"
              >
                {link.label}
              </Link>
            ))}
            <Button asChild variant="primary" size="md" className="mt-2">
              <Link href="/ingresar">Ingresar</Link>
            </Button>
          </nav>
        </div>
      </header>

      <main id="contenido" className="flex-1">
        {children}
      </main>

      <footer className="border-t border-[var(--border-subtle)] bg-[var(--surface-sunken)]">
        <div className="mx-auto flex w-full max-w-6xl flex-col gap-6 px-5 py-10 sm:flex-row sm:items-start sm:justify-between">
          <div className="max-w-sm">
            <Logo />
            <p className="mt-3 text-[12.5px] leading-relaxed text-[var(--text-secondary)]">
              Inspección, certificación y trazabilidad de equipamiento de presión para la industria
              energética.
            </p>
          </div>

          <div className="flex gap-12">
            <div>
              <p className="mb-2 text-[10.5px] font-semibold tracking-[0.1em] text-[var(--text-muted)] uppercase">
                Plataforma
              </p>
              <ul className="flex flex-col gap-1.5 text-[12.5px]">
                <li>
                  <Link href="/servicios" className="text-[var(--text-secondary)] hover:text-[var(--text-accent)]">
                    Servicios
                  </Link>
                </li>
                <li>
                  <Link href="/ingresar" className="text-[var(--text-secondary)] hover:text-[var(--text-accent)]">
                    Portal de clientes
                  </Link>
                </li>
                <li>
                  <Link
                    href="/solicitar-acceso"
                    className="text-[var(--text-secondary)] hover:text-[var(--text-accent)]"
                  >
                    Solicitar acceso
                  </Link>
                </li>
              </ul>
            </div>
          </div>
        </div>

        <div className="border-t border-[var(--border-subtle)] px-5 py-4">
          <p className="mx-auto w-full max-w-6xl text-[11.5px] text-[var(--text-muted)]">
            © {new Date().getFullYear()} {BRAND_NAME}. Todos los derechos reservados.
          </p>
        </div>
      </footer>
    </div>
  );
}
