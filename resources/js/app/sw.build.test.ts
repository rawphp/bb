// @vitest-environment node
import { existsSync, readFileSync, rmSync } from 'node:fs';
import { build } from 'vite';
import { describe, expect, it } from 'vitest';

// The worker the production build ships, with the precache list Workbox injected.
async function builtWorker(): Promise<string[]> {
    rmSync('public/sw.js', { force: true });
    await build({ configFile: 'vite.config.ts', logLevel: 'silent' });
    expect(existsSync('public/sw.js'), 'the build writes public/sw.js').toBe(true);

    return [...readFileSync('public/sw.js', 'utf8').matchAll(/"url":"([^"]+)"/g)].map((match) => match[1]!);
}

describe('built service worker', () => {
    it('PWA-004 the build writes the worker to /sw.js, precaching build/**, the icons and the manifest but no launch image', async () => {
        const urls = await builtWorker();

        expect(urls.some((url) => /^build\/assets\/.+\.js$/.test(url))).toBe(true);
        expect(urls.some((url) => /^build\/assets\/.+\.css$/.test(url))).toBe(true);
        expect(urls).toEqual(expect.arrayContaining(['icons/icon-192.png', 'icons/icon-512.png', 'icons/maskable-512.png', 'manifest.webmanifest']));
        expect(urls.filter((url) => url.includes('launch/'))).toEqual([]);
        expect(urls.filter((url) => !/^(build\/|icons\/[^/]+\.png$|manifest\.webmanifest$)/.test(url))).toEqual([]);
    }, 120_000);
});
