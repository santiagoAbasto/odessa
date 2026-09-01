import { Link, router, usePage } from '@inertiajs/react';
import { AnimatePresence, motion, useReducedMotion } from 'motion/react';
import {
  Bell,
  ChevronRight,
  LogOut,
  Menu,
  Monitor,
  Moon,
  PanelLeftClose,
  PanelLeftOpen,
  Search,
  Sun,
  UserCircle2,
  X,
} from 'lucide-react';
import { useEffect, useState, type ReactNode } from 'react';
import { BRAND_NAME, Logo, LogoMark } from '@/components/brand/logo';
import { navigation } from '@/components/layout/navigation';
import { Button } from '@/components/ui/button';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Tooltip, TooltipProvider } from '@/components/ui/tooltip';
import { usePermissions } from '@/hooks/use-permissions';
import { cn } from '@/lib/cn';
import type { PageProps } from '@/types';

export interface Breadcrumb {
  label: string;
  href?: string;
}

interface AppLayoutProps {
  title: string;
  description?: string;
  breadcrumbs?: Breadcrumb[];
  actions?: ReactNode;
  children: ReactNode;
}

type Theme = 'light' | 'dark' | 'system';

export default function AppLayout({
  title,
  description,
  breadcrumbs = [],
  actions,
  children,
}: AppLayoutProps) {
  const { auth } = usePage<PageProps>().props;
  const { can } = usePermissions();
  const { url } = usePage();
  const reduceMotion = useReducedMotion();

  const [collapsed, setCollapsed] = useState(false);
  const [mobileOpen, setMobileOpen] = useState(false);
  const [theme, setTheme] = useState<Theme>('system');

  // El sidebar recuerda su estado entre visitas.
  useEffect(() => {
    setCollapsed(localStorage.getItem('odessa.sidebar.collapsed') === 'true');
    const savedTheme = (localStorage.getItem('odessa.theme') as Theme | null) ?? 'system';
    setTheme(savedTheme);
    applyTheme(savedTheme);
  }, []);

  // Al navegar se cierra el menú móvil: evita quedar tapado tras un clic.
  useEffect(() => {
    setMobileOpen(false);
  }, [url]);

  const toggleCollapsed = () => {
    setCollapsed((previous) => {
      localStorage.setItem('odessa.sidebar.collapsed', String(!previous));
      return !previous;
    });
  };

  const changeTheme = (next: Theme) => {
    setTheme(next);
    localStorage.setItem('odessa.theme', next);
    applyTheme(next);
  };

  const user = auth.user;
  if (!user) return null;

  const visibleGroups = navigation
    .map((group) => ({
      ...group,
      items: group.items.filter((item) => !item.permission || can(item.permission)),
    }))
    .filter((group) => group.items.length > 0);

  const sidebarWidth = collapsed ? 68 : 250;

  const sidebarContent = (
    <>
      <div
        className={cn(
          'flex h-14 items-center border-b border-white/10 px-4',
          collapsed && 'justify-center px-0',
        )}
      >
        <Link href="/panel" className="flex items-center" aria-label="Odessa Oil, ir al panel">
          <Logo onDark compact={collapsed} subtitle="Inspección técnica" />
        </Link>
        <button
          type="button"
          onClick={() => setMobileOpen(false)}
          className="ml-auto grid size-8 place-items-center rounded-xs text-white/60 hover:bg-white/10 hover:text-white lg:hidden"
          aria-label="Cerrar menú"
        >
          <X className="size-4" aria-hidden />
        </button>
      </div>

      {/* Organización activa: el dato que evita confundir de quién son los datos. */}
      {!collapsed && (
        <div className="mx-3 mt-3 rounded-sm border border-white/10 bg-white/5 px-3 py-2.5">
          <p className="text-[9.5px] font-semibold tracking-[0.12em] text-white/45 uppercase">
            {user.isStaff ? 'Acceso interno' : 'Organización'}
          </p>
          <p className="mt-1 truncate text-[12.5px] font-medium text-white">
            {user.organization?.name ?? BRAND_NAME}
          </p>
          <p className="mt-0.5 truncate text-[10.5px] text-white/50">
            {user.roleLabels[0] ?? user.roles[0] ?? 'Sin rol'}
          </p>
        </div>
      )}

      <nav className="flex-1 overflow-y-auto px-2.5 py-3" aria-label="Navegación principal">
        {visibleGroups.map((group) => (
          <div key={group.label} className="mb-4 last:mb-0">
            {!collapsed && (
              <p className="mb-1.5 px-2 text-[9.5px] font-semibold tracking-[0.12em] text-white/35 uppercase">
                {group.label}
              </p>
            )}
            <ul className="flex flex-col gap-0.5">
              {group.items.map((item) => {
                const active = item.match.some((prefix) => url.startsWith(prefix));
                const link = (
                  <Link
                    href={item.href}
                    aria-current={active ? 'page' : undefined}
                    className={cn(
                      'flex h-9 items-center gap-2.5 rounded-xs px-2 text-[12.5px] transition-colors',
                      collapsed && 'justify-center px-0',
                      active
                        ? 'bg-white/95 font-medium text-[var(--color-steel-900)]'
                        : 'text-white/70 hover:bg-white/10 hover:text-white',
                    )}
                  >
                    <item.icon className="size-4 shrink-0" aria-hidden />
                    {!collapsed && <span className="truncate">{item.label}</span>}
                  </Link>
                );

                return (
                  <li key={item.href}>
                    {collapsed ? <Tooltip content={item.label}>{link}</Tooltip> : link}
                  </li>
                );
              })}
            </ul>
          </div>
        ))}
      </nav>

      <div className="border-t border-white/10 p-2.5">
        <button
          type="button"
          onClick={toggleCollapsed}
          className={cn(
            'hidden h-9 w-full items-center gap-2.5 rounded-xs px-2 text-[12.5px] text-white/60',
            'hover:bg-white/10 hover:text-white lg:flex',
            collapsed && 'justify-center px-0',
          )}
          aria-label={collapsed ? 'Expandir menú lateral' : 'Contraer menú lateral'}
        >
          {collapsed ? (
            <PanelLeftOpen className="size-4" aria-hidden />
          ) : (
            <>
              <PanelLeftClose className="size-4" aria-hidden />
              <span>Contraer</span>
            </>
          )}
        </button>
      </div>
    </>
  );

  return (
    <TooltipProvider>
      <a href="#contenido" className="skip-link">
        Saltar al contenido
      </a>

      <div className="flex min-h-screen">
        {/* --- Sidebar de escritorio --- */}
        <motion.aside
          animate={{ width: sidebarWidth }}
          initial={false}
          transition={reduceMotion ? { duration: 0 } : { duration: 0.18, ease: 'easeOut' }}
          className="sticky top-0 hidden h-screen shrink-0 flex-col bg-[var(--color-steel-950)] lg:flex"
        >
          {sidebarContent}
        </motion.aside>

        {/* --- Sidebar móvil --- */}
        <AnimatePresence>
          {mobileOpen && (
            <>
              <motion.button
                type="button"
                initial={{ opacity: 0 }}
                animate={{ opacity: 1 }}
                exit={{ opacity: 0 }}
                transition={{ duration: reduceMotion ? 0 : 0.15 }}
                onClick={() => setMobileOpen(false)}
                className="fixed inset-0 z-40 bg-black/50 lg:hidden"
                aria-label="Cerrar menú"
              />
              <motion.aside
                initial={{ x: '-100%' }}
                animate={{ x: 0 }}
                exit={{ x: '-100%' }}
                transition={reduceMotion ? { duration: 0 } : { duration: 0.2, ease: 'easeOut' }}
                className="fixed top-0 left-0 z-50 flex h-screen w-[270px] flex-col bg-[var(--color-steel-950)] lg:hidden"
              >
                {sidebarContent}
              </motion.aside>
            </>
          )}
        </AnimatePresence>

        {/* --- Área principal --- */}
        <div className="flex min-w-0 flex-1 flex-col">
          <header className="sticky top-0 z-30 flex h-14 items-center gap-3 border-b border-[var(--border-subtle)] bg-[var(--surface-raised)] px-4">
            <Button
              variant="ghost"
              size="icon"
              className="lg:hidden"
              onClick={() => setMobileOpen(true)}
              aria-label="Abrir menú"
            >
              <Menu aria-hidden />
            </Button>

            <div className="hidden lg:block">
              <LogoMark className="h-5" />
            </div>

            <GlobalSearch />

            <div className="ml-auto flex shrink-0 items-center gap-1.5">
              <DropdownMenu>
                <DropdownMenuTrigger asChild>
                  <Button variant="ghost" size="icon" aria-label="Cambiar tema">
                    {theme === 'dark' ? <Moon aria-hidden /> : theme === 'light' ? <Sun aria-hidden /> : <Monitor aria-hidden />}
                  </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent>
                  <DropdownMenuLabel>Apariencia</DropdownMenuLabel>
                  <DropdownMenuItem onSelect={() => changeTheme('light')}>
                    <Sun aria-hidden /> Claro
                  </DropdownMenuItem>
                  <DropdownMenuItem onSelect={() => changeTheme('dark')}>
                    <Moon aria-hidden /> Oscuro
                  </DropdownMenuItem>
                  <DropdownMenuItem onSelect={() => changeTheme('system')}>
                    <Monitor aria-hidden /> Según el sistema
                  </DropdownMenuItem>
                </DropdownMenuContent>
              </DropdownMenu>

              <Button variant="ghost" size="icon" aria-label="Notificaciones">
                <Bell aria-hidden />
              </Button>

              <DropdownMenu>
                <DropdownMenuTrigger asChild>
                  <button
                    type="button"
                    className="flex items-center gap-2 rounded-xs px-1.5 py-1 hover:bg-[var(--surface-hover)]"
                    aria-label={`Cuenta de ${user.name}`}
                  >
                    <span className="grid size-7 shrink-0 place-items-center rounded-xs bg-[var(--accent)] text-[11px] font-semibold text-[var(--text-inverse)]">
                      {user.initials}
                    </span>
                    <span className="hidden max-w-36 flex-col items-start leading-tight sm:flex">
                      <span className="truncate text-[12px] font-medium">{user.name}</span>
                      <span className="truncate text-[10.5px] text-[var(--text-muted)]">
                        {user.roleLabels[0] ?? ''}
                      </span>
                    </span>
                  </button>
                </DropdownMenuTrigger>
                <DropdownMenuContent>
                  <DropdownMenuLabel>Sesión</DropdownMenuLabel>
                  <div className="px-2 pb-1.5">
                    <p className="truncate text-[12px] font-medium">{user.name}</p>
                    <p className="truncate text-[11px] text-[var(--text-muted)]">{user.email}</p>
                  </div>
                  <DropdownMenuSeparator />
                  <DropdownMenuItem>
                    <UserCircle2 aria-hidden /> Mi perfil
                  </DropdownMenuItem>
                  <DropdownMenuSeparator />
                  <DropdownMenuItem destructive onSelect={() => router.post('/salir')}>
                    <LogOut aria-hidden /> Cerrar sesión
                  </DropdownMenuItem>
                </DropdownMenuContent>
              </DropdownMenu>
            </div>
          </header>

          <main id="contenido" className="flex-1 px-4 py-5 sm:px-6 sm:py-6">
            <div className="mx-auto w-full max-w-[1600px]">
              {breadcrumbs.length > 0 && (
                <nav aria-label="Ruta de navegación" className="mb-2.5">
                  <ol className="flex flex-wrap items-center gap-1 text-[11.5px] text-[var(--text-muted)]">
                    {breadcrumbs.map((crumb, index) => (
                      <li key={`${crumb.label}-${index}`} className="flex items-center gap-1">
                        {index > 0 && <ChevronRight className="size-3" aria-hidden />}
                        {crumb.href && index < breadcrumbs.length - 1 ? (
                          <Link href={crumb.href} className="hover:text-[var(--text-accent)]">
                            {crumb.label}
                          </Link>
                        ) : (
                          <span aria-current={index === breadcrumbs.length - 1 ? 'page' : undefined}>
                            {crumb.label}
                          </span>
                        )}
                      </li>
                    ))}
                  </ol>
                </nav>
              )}

              <div className="mb-5 flex flex-wrap items-end justify-between gap-3">
                <div className="min-w-0">
                  <h1 className="text-[22px] leading-tight font-semibold tracking-tight">{title}</h1>
                  {description && (
                    <p className="mt-1 max-w-2xl text-[13px] text-[var(--text-secondary)]">
                      {description}
                    </p>
                  )}
                </div>
                {actions && <div className="flex shrink-0 items-center gap-2">{actions}</div>}
              </div>

              {children}
            </div>
          </main>
        </div>
      </div>
    </TooltipProvider>
  );
}

/** Buscador global: salta directo al listado de activos con el término aplicado. */
function GlobalSearch() {
  const [term, setTerm] = useState('');

  useEffect(() => {
    const handler = (event: KeyboardEvent) => {
      if ((event.metaKey || event.ctrlKey) && event.key === 'k') {
        event.preventDefault();
        document.getElementById('global-search')?.focus();
      }
    };
    window.addEventListener('keydown', handler);
    return () => window.removeEventListener('keydown', handler);
  }, []);

  return (
    <form
      role="search"
      onSubmit={(event) => {
        event.preventDefault();
        if (term.trim()) router.get('/activos', { search: term.trim() });
      }}
      className="relative min-w-0 max-w-md flex-1"
    >
      <label htmlFor="global-search" className="sr-only">
        Buscar activos por descripción, serie o código
      </label>
      <Search
        className="pointer-events-none absolute top-1/2 left-2.5 size-3.5 -translate-y-1/2 text-[var(--text-muted)]"
        aria-hidden
      />
      <input
        id="global-search"
        type="search"
        value={term}
        onChange={(event) => setTerm(event.target.value)}
        placeholder="Buscar activo, serie o código…"
        className="h-8 w-full rounded-xs border border-[var(--border-default)] bg-[var(--surface-sunken)] pr-12 pl-8 text-[12.5px] outline-none focus:border-[var(--focus-ring)] focus:bg-[var(--surface-raised)]"
      />
      <kbd className="pointer-events-none absolute top-1/2 right-2 hidden -translate-y-1/2 rounded-xs border border-[var(--border-default)] bg-[var(--surface-raised)] px-1 text-[10px] text-[var(--text-muted)] sm:block">
        ⌘K
      </kbd>
    </form>
  );
}

function applyTheme(theme: Theme) {
  const root = document.documentElement;
  if (theme === 'system') {
    root.removeAttribute('data-theme');
  } else {
    root.setAttribute('data-theme', theme);
  }
}
