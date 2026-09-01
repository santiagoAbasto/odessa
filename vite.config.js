import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import { fileURLToPath, URL } from 'node:url';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.tsx'],
            refresh: true,
        }),
        tailwindcss(),
    ],
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
        },
    },
    build: {
        rollupOptions: {
            output: {
                // Separa las dependencias pesadas para que la primera carga del
                // panel no arrastre el motor de gráficos.
                manualChunks(id) {
                    if (!id.includes('node_modules')) return;
                    if (id.includes('echarts') || id.includes('zrender')) return 'charts';
                    if (id.includes('react-dom') || id.includes('@inertiajs')) return 'vendor';
                },
            },
        },
    },
    server: {
        watch: { ignored: ['**/storage/framework/views/**'] },
    },
});
