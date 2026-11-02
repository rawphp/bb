// The app shell the service worker precaches (OFF-002). The server answers every
// app path with the same HTML (routes/web.php), so one cached copy of `/` opens
// any screen offline, and the client router picks the screen.

export const SHELL_URL = '/';

// Paths the server answers itself; a navigation to one never gets the shell. It must
// cover the paths routes/web.php keeps from the shell (shell.test.ts checks), plus
// build, which the web server serves as files.
export const SERVER_PATHS = /^\/(capabilities|sanctum|mcp|api|build)(\/|$)/;

type ManifestEntry = string | { url: string; revision?: string | null };

/**
 * The precache entry for the shell. The HTML names the hashed build assets, so
 * its revision is derived from theirs: a build that changes an asset refetches it.
 */
export function shellEntry(manifest: readonly ManifestEntry[]): { url: string; revision: string } {
    const assets = manifest.map((entry) => (typeof entry === 'string' ? entry : `${entry.url}@${entry.revision ?? ''}`)).sort();

    return { url: SHELL_URL, revision: hash(assets.join('\n')) };
}

// FNV-1a: a short, stable fingerprint, not a security boundary.
export function hash(text: string): string {
    let h = 0x811c9dc5;
    for (let i = 0; i < text.length; i++) {
        h ^= text.charCodeAt(i);
        h = Math.imul(h, 0x01000193);
    }

    return (h >>> 0).toString(16);
}
