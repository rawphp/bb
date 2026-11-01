import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import laravel from 'laravel-vite-plugin';
import { defineConfig } from 'vite';
import { VitePWA } from 'vite-plugin-pwa';

export default defineConfig({
    server: { port: 5283, strictPort: true },
    resolve: { alias: { '@': '/resources/js' } },
    plugins: [
        laravel({ input: ['resources/js/app/main.ts'], refresh: true }),
        vue({ template: { transformAssetUrls: { base: null, includeAbsolute: false } } }),
        tailwindcss(),
        // The service worker is ours (injectManifest). CACHE (#18) and SHELL_PWA
        // add caching, registration and the manifest.
        VitePWA({
            strategies: 'injectManifest',
            srcDir: 'resources/js/app',
            filename: 'sw.ts',
            injectRegister: false,
            manifest: false,
        }),
    ],
});
