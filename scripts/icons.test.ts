import { existsSync, readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';
import { APPLE_TOUCH_ICON, headTags, ICONS, launchFile, launchMedia, manifest, SCREENS, token } from './icons.mjs';

// The committed output of `npm run icons`, checked against the tokens and on disk.
const css = readFileSync('resources/js/design/tokens.css', 'utf8');
const background = token(css, 'background');
const webManifest = JSON.parse(readFileSync('public/manifest.webmanifest', 'utf8'));
const head = readFileSync('resources/views/pwa.blade.php', 'utf8');

function pngSize(path: string): { width: number; height: number } {
    const bytes = readFileSync(`public${path}`);

    return { width: bytes.readUInt32BE(16), height: bytes.readUInt32BE(20) };
}

describe('icons', () => {
    it('PWA-001 commits the manifest and head tags the current tokens produce', () => {
        expect(webManifest).toEqual(manifest(background));
        expect(head).toBe(headTags(background));
    });

    it('PWA-001 opens standalone, in portrait, in the page background colour', () => {
        expect(webManifest).toMatchObject({ display: 'standalone', orientation: 'portrait', start_url: '/', scope: '/' });
        expect(webManifest.theme_color).toBe(background.light);
        expect(webManifest.background_color).toBe(background.light);
        expect(head).toContain(`<meta name="theme-color" media="(prefers-color-scheme: dark)" content="${background.dark}">`);
    });

    it('PWA-001 lists any and maskable icons at 192 and 512, each a PNG of its stated size', () => {
        for (const purpose of ['any', 'maskable']) {
            expect(webManifest.icons.filter((icon: { purpose: string }) => icon.purpose === purpose).map((icon: { sizes: string }) => icon.sizes)).toEqual([
                '192x192',
                '512x512',
            ]);
        }
        for (const icon of webManifest.icons as { src: string; sizes: string }[]) {
            const { width, height } = pngSize(icon.src);
            expect(`${width}x${height}`, icon.src).toBe(icon.sizes);
        }
        expect(ICONS).toHaveLength(webManifest.icons.length);
    });

    it('PWA-002 gives iOS the web app tags and a 180 point touch icon', () => {
        expect(head).toContain('<meta name="apple-mobile-web-app-capable" content="yes">');
        expect(head).toContain('<meta name="apple-mobile-web-app-status-bar-style" content="default">');
        expect(head).toContain(`<link rel="apple-touch-icon" href="/icons/${APPLE_TOUCH_ICON.file}">`);
        expect(pngSize(`/icons/${APPLE_TOUCH_ICON.file}`)).toEqual({ width: 180, height: 180 });
    });

    it.each(SCREENS)('PWA-002 has light and dark launch images for an iPhone of $width x $height at $ratio x', (screen) => {
        for (const scheme of ['light', 'dark'] as const) {
            const href = `/icons/launch/${launchFile(screen, scheme)}`;
            expect(head).toContain(`<link rel="apple-touch-startup-image" media="${launchMedia(screen, scheme)}" href="${href}">`);
            expect(existsSync(`public${href}`), href).toBe(true);
            expect(pngSize(href)).toEqual({ width: screen.width * screen.ratio, height: screen.height * screen.ratio });
        }
    });

    it('PWA-002 covers the current iPhone sizes', () => {
        const sizes = SCREENS.map(({ width, height, ratio }) => `${width}x${height}@${ratio}`);

        expect(sizes).toEqual(expect.arrayContaining(['440x956@3', '402x874@3', '393x852@3', '390x844@3', '375x667@2']));
        expect(new Set(sizes).size).toBe(sizes.length);
    });
});
