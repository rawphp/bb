import { describe, expect, it } from 'vitest';
import { SERVER_PATHS, shellEntry } from './shell';

describe('shell', () => {
    const manifest = [
        { url: 'assets/main-a.js', revision: null },
        { url: 'assets/main-a.css', revision: null },
        { url: 'icons/icon.svg', revision: '1' },
    ];

    it('OFF-002 precaches the shell HTML with a revision that changes when a build asset changes', () => {
        const entry = shellEntry(manifest);

        expect(entry.url).toBe('/');
        expect(shellEntry([...manifest].reverse())).toEqual(entry);
        expect(shellEntry([{ url: 'assets/main-b.js', revision: null }, ...manifest.slice(1)]).revision).not.toBe(entry.revision);
        expect(shellEntry([...manifest.slice(0, 2), { url: 'icons/icon.svg', revision: '2' }]).revision).not.toBe(entry.revision);
    });

    it.each(['/', '/account', '/today/2026-11-01', '/capabilitiesx', '/dev/gallery'])('OFF-002 opens %s from the shell', (path) => {
        expect(SERVER_PATHS.test(path)).toBe(false);
    });

    it.each(['/capabilities/account_show', '/sanctum/csrf-cookie', '/mcp', '/api/x', '/build/sw.js'])(
        'OFF-002 leaves %s to the server',
        (path) => {
            expect(SERVER_PATHS.test(path)).toBe(true);
        },
    );
});
