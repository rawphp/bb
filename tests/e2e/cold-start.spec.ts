import { chromium, devices, expect, test, type BrowserContext, type Response } from '@playwright/test';
import { mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { signIn } from './support';

// Lighthouse's mobile "slow 4G" profile, as Chrome DevTools applies it, and its 4× CPU slowdown.
const SLOW_4G = { offline: false, latency: 150 * 3.75, downloadThroughput: (1.6 * 1024 * 1024 * 0.9) / 8, uploadThroughput: (750 * 1024 * 0.9) / 8 };
const CPU_SLOWDOWN = 4;

// The trace (test-results/, and the HTML report) shows the start frame by frame.
test.use({ trace: 'on' });

let profile: string;

test.beforeEach(() => {
    profile = mkdtempSync(join(tmpdir(), 'bb-cold-start-'));
});

test.afterEach(() => {
    rmSync(profile, { recursive: true, force: true });
});

/** The installed app: one Chromium profile, launched again for each start. */
function launch(): Promise<BrowserContext> {
    return chromium.launchPersistentContext(profile, { ...devices['Pixel 7'], viewport: { width: 390, height: 844 }, baseURL: test.info().project.use.baseURL });
}

test('PWA-004 a cold start of the installed app shows the cached Today screen within 1 second on slow 4G and a 4x slower CPU', async ({}, testInfo) => {
    // Install: the first launch signs in, the worker precaches the shell and build, and Today's read is cached.
    const install = await launch();
    const first = install.pages()[0] ?? (await install.newPage());
    await signIn(first);
    await first.evaluate(() => navigator.serviceWorker.ready.then(() => undefined));
    await first.waitForLoadState('networkidle');
    await install.close();

    // Cold start: the browser launches again on that profile, with no worker running and nothing in memory.
    // The page is throttled before it loads anything and records when the first meal card is painted.
    const context = await launch();
    try {
        const page = context.pages()[0] ?? (await context.newPage());
        const cdp = await context.newCDPSession(page);
        await cdp.send('Network.enable');
        await cdp.send('Network.emulateNetworkConditions', SLOW_4G);
        await cdp.send('Emulation.setCPUThrottlingRate', { rate: CPU_SLOWDOWN });
        await page.addInitScript(() => {
            const seen = () => {
                if (!document.querySelector('[data-test="meal-card"]')) return false;
                requestAnimationFrame(() => ((window as unknown as { todayPainted: number }).todayPainted = performance.now()));
                return true;
            };
            new MutationObserver((_, observer) => seen() && observer.disconnect()).observe(document, { childList: true, subtree: true });
        });
        const shell: Response[] = [];
        page.on('response', (response) => {
            if (response.request().resourceType() === 'document' || new URL(response.url()).pathname.startsWith('/build/')) shell.push(response);
        });

        await page.goto('/today');
        const painted = (await (await page.waitForFunction(() => (window as unknown as { todayPainted?: number }).todayPainted, null, { timeout: 30_000 })).jsonValue()) as number;
        testInfo.annotations.push({ type: 'cold start to Today', description: `${Math.round(painted)} ms` });

        // From cache: the worker answered the page and its build, and no read had come back from the server yet.
        expect(shell.length).toBeGreaterThan(1);
        expect(shell.filter((response) => !response.fromServiceWorker()).map((response) => response.url())).toEqual([]);
        const readsBeforePaint = await page.evaluate(
            (at) =>
                performance
                    .getEntriesByType('resource')
                    .filter((entry) => new URL(entry.name).pathname.startsWith('/capabilities/') && (entry as PerformanceResourceTiming).responseEnd <= at)
                    .map((entry) => entry.name),
            painted,
        );
        expect(readsBeforePaint).toEqual([]);
        expect(painted).toBeLessThan(1000);
    } finally {
        await context.close();
    }
});
