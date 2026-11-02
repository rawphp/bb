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

/** The URLs Workbox injected into a built worker. */
export function precachedUrls(source) {
    return [...source.matchAll(/"url":"([^"]+)"/g)].map((match) => match[1]);
}

/** What is wrong with a precache list; empty when nothing is. */
export function precacheProblems(urls) {
    const problems = [];
    if (!urls.some((url) => /^build\/assets\/.+\.js$/.test(url))) problems.push('no build/assets JavaScript');
    if (!urls.some((url) => /^build\/assets\/.+\.css$/.test(url))) problems.push('no build/assets CSS');
    for (const url of REQUIRED) {
        if (!urls.includes(url)) problems.push(`no ${url}`);
    }
    for (const url of urls) {
        if (!ALLOWED.test(url)) problems.push(`unexpected ${url}`);
    }

    return problems;
}

if (process.argv[1] === fileURLToPath(import.meta.url)) {
    const problems = existsSync(WORKER) ? precacheProblems(precachedUrls(readFileSync(WORKER, 'utf8'))) : ['missing: the build must write the worker there'];
    if (problems.length > 0) {
        console.error(`${WORKER}: ${problems.join('; ')}`);
        process.exit(1);
    }
    console.log(`${WORKER}: precaches build/**, the icons and the manifest`);
}
