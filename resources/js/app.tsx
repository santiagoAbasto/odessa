import '../css/app.css';

import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import { StrictMode, type ComponentType } from 'react';
import { Toaster } from 'sonner';

const appName = import.meta.env.VITE_APP_NAME ?? 'Odessa Oil';

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
