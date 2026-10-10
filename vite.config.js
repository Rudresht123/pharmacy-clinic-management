import { fileURLToPath, URL } from 'node:url';
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                'resources/js/app/main.tsx',
            ],
            refresh: true,
        }),
        react(),
    ],
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
        },
    },
    server: {
        // Every tenant is reached at its own *.hms.local subdomain (see
        // config/organization.php), so the dev server sees a different
        // request origin per tenant. Without this, the browser blocks the
        // cross-origin module scripts and every tenant screen is stuck on
        // "Loading…" forever.
        cors: true,
    },
});
