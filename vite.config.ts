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
        // The service worker is ours (injectManifest) and precaches the shell (OFF-002).
        // It builds to public/sw.js so its scope is / (PWA-004); updates.ts registers it.
        // The manifest and icons are committed files from `npm run icons` (PWA-001).
        VitePWA({
            strategies: 'injectManifest',
            srcDir: 'resources/js/app',
            filename: 'sw.ts',
            outDir: 'public',
            injectRegister: false,
            manifest: false,
            injectManifest: {
                globPatterns: ['build/**/*.{js,css,woff,woff2,svg,png,ico,webp}', 'icons/*.png', 'manifest.webmanifest'],
                dontCacheBustURLsMatching: /^build\/assets\//,
            },
        }),
    ],
});
