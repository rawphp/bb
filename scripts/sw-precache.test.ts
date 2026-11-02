import { describe, expect, it } from 'vitest';
import { precachedUrls, precacheProblems } from './sw-precache.mjs';

// The list a good build injects (see scripts/check.sh, which checks the real one after `vite build`).
const GOOD = [
    'build/assets/main-a1.js',
    'build/assets/main-b2.css',
    'icons/icon-192.png',
    'icons/icon-512.png',
    'icons/maskable-192.png',
    'icons/maskable-512.png',
    'icons/apple-touch-icon.png',
    'manifest.webmanifest',
];

describe('sw-precache', () => {
    it('PWA-004 reads the URLs Workbox injected into the built worker', () => {
        const source = 'precacheAndRoute([{"revision":null,"url":"build/assets/main-a1.js"},{"revision":"x","url":"icons/icon-192.png"}])';

        expect(precachedUrls(source)).toEqual(['build/assets/main-a1.js', 'icons/icon-192.png']);
    });

    it('PWA-004 a precache of build/**, the icons and the manifest passes', () => {
        expect(precacheProblems(GOOD)).toEqual([]);
    });

    it('PWA-004 a precache without the build JavaScript or CSS fails', () => {
        expect(precacheProblems(GOOD.filter((url) => !url.endsWith('.js')))).toEqual(['no build/assets JavaScript']);
        expect(precacheProblems(GOOD.filter((url) => !url.endsWith('.css')))).toEqual(['no build/assets CSS']);
    });

    it('PWA-004 a precache missing an icon or the manifest fails', () => {
        expect(precacheProblems(GOOD.filter((url) => url !== 'icons/maskable-512.png'))).toEqual(['no icons/maskable-512.png']);
        expect(precacheProblems(GOOD.filter((url) => url !== 'manifest.webmanifest'))).toEqual(['no manifest.webmanifest']);
    });

    it('PWA-004 a launch image or a file outside build, icons and the manifest fails', () => {
        expect(precacheProblems([...GOOD, 'icons/launch/750x1334-light.png', 'index.php'])).toEqual([
            'unexpected icons/launch/750x1334-light.png',
            'unexpected index.php',
        ]);
    });
});
