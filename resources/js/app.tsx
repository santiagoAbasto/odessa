import '../css/app.css';

import { createInertiaApp, router } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import { StrictMode, type ComponentType } from 'react';
import { toast, Toaster } from 'sonner';

const appName = import.meta.env.VITE_APP_NAME ?? 'Odessa Oil';

/*
  Lo que el servidor avisa. Los controladores responden con `with('success', …)`
  y las reglas de negocio rechazan con errores de validación: sin esto la
  pantalla no mostraba ni uno ni otro, y un botón que fallaba parecía no hacer
  nada.
*/
router.on('success', (event) => {
  const flash = (event.detail.page.props as { flash?: Record<string, string | null | undefined> }).flash ?? {};

  for (const tipo of ['success', 'error', 'warning', 'info'] as const) {
    if (flash[tipo]) toast[tipo](flash[tipo]);
  }
});

router.on('error', (event) => {
  const primero = Object.values(event.detail.errors)[0];

  if (primero) toast.error(String(primero));
});

/**
 * Tema inicial antes del primer pintado.
 *
 * El blade ya aplica el atributo; esto lo repite por si la página se monta sin
 * pasar por la plantilla (por ejemplo, en pruebas de navegador).
 */
const savedTheme = localStorage.getItem('odessa.theme');
if (savedTheme === 'dark' || savedTheme === 'light') {
  document.documentElement.setAttribute('data-theme', savedTheme);
}

void createInertiaApp({
  title: (title) => (title ? `${title} · ${appName}` : appName),

  resolve: async (name) => {
    const pages = import.meta.glob<{ default: ComponentType }>('./pages/**/*.tsx');
    const page = pages[`./pages/${name}.tsx`];

    if (!page) {
      throw new Error(`No se encontró la página Inertia «${name}».`);
    }

    const loaded = await page();

    return loaded.default;
  },

  setup(options) {
    const { el, App, props } = options;

    if (!el) return;

    createRoot(el).render(
      <StrictMode>
        <App {...props} />
        <Toaster position="bottom-right" richColors closeButton toastOptions={{ duration: 5000 }} />
      </StrictMode>,
    );
  },

  progress: {
    color: 'oklch(0.494 0.192 265)',
    showSpinner: false,
  },
});
