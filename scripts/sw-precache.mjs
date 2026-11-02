// PWA-004: the worker the production build ships. scripts/check.sh runs this after
// `vite build`. The worker must sit at public/sw.js, so its scope is /, and precache
// build/**, the icons and the manifest, but no launch image (iOS reads those when the
// app is installed). A unit test cannot see this: it lives in vite.config.ts and the build.
import { existsSync, readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { ICON_FILES } from './icons.mjs';

export const WORKER = 'public/sw.js';

const REQUIRED = [...ICON_FILES.map((file) => `icons/${file}`), 'manifest.webmanifest'];
const ALLOWED = /^(build\/.+|icons\/[^/]+\.png|manifest\.webmanifest)$/;

/** The entries Workbox injected into a built worker: { url, revision }. */
export function precachedEntries(source) {
    return [...source.matchAll(/\{[^{}]*"url":"[^"]+"[^{}]*\}/g)].map((match) => {
        const { url, revision = null } = JSON.parse(match[0]);
        return { url, revision };
    });
}

/** What is wrong with a precache list; empty when nothing is. */
export function precacheProblems(entries) {
    const urls = entries.map((entry) => entry.url);
    const problems = [];
    if (!urls.some((url) => /^build\/assets\/.+\.js$/.test(url))) problems.push('no build/assets JavaScript');
    if (!urls.some((url) => /^build\/assets\/.+\.css$/.test(url))) problems.push('no build/assets CSS');
    for (const url of REQUIRED) {
        if (!urls.includes(url)) problems.push(`no ${url}`);
    }
    for (const url of urls) {
        if (!ALLOWED.test(url)) problems.push(`unexpected ${url}`);
    }
    // The hashed name is the revision. A revisioned build file is keyed url?__WB_REVISION__=…,
    // which the precache's lookups and sw.ts's previous-build copy must then see past;
    // vite.config.ts's dontCacheBustURLsMatching keeps them plain.
    for (const { url, revision } of entries) {
        if (url.startsWith('build/') && revision !== null) problems.push(`${url} has a revision; its hashed name should be enough`);
    }

    return problems;
}

if (process.argv[1] === fileURLToPath(import.meta.url)) {
    const problems = existsSync(WORKER) ? precacheProblems(precachedEntries(readFileSync(WORKER, 'utf8'))) : ['missing: the build must write the worker there'];
    if (problems.length > 0) {
        console.error(`${WORKER}: ${problems.join('; ')}`);
        process.exit(1);
    }
    console.log(`${WORKER}: precaches build/**, the icons and the manifest`);
}
