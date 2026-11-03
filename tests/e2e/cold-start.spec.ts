import { expect, test } from '@playwright/test';
import { signIn } from './support';

// Lighthouse's mobile "slow 4G" profile, as Chrome DevTools applies it, and its 4× CPU slowdown.
const SLOW_4G = { offline: false, latency: 150 * 3.75, downloadThroughput: (1.6 * 1024 * 1024 * 0.9) / 8, uploadThroughput: (750 * 1024 * 0.9) / 8 };
const CPU_SLOWDOWN = 4;

// The trace (test-results/, and the HTML report) shows the start frame by frame.
test.use({ trace: 'on' });

test('PWA-004 a cold start of the installed app shows the cached Today screen within 1 second on slow 4G and a 4x slower CPU', async ({ context }, testInfo) => {
    // Install: the first visit signs in, the worker precaches the shell and build, and Today's read is cached.
    const first = await context.newPage();
    await signIn(first);
    await first.evaluate(() => navigator.serviceWorker.ready.then(() => undefined));
    await first.waitForLoadState('networkidle');
    await first.close();

    // Cold start: a new page, throttled before it loads anything, records when the first meal card is painted.
    const page = await context.newPage();
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

    await page.goto('/today');
    const painted = await page.waitForFunction(() => (window as unknown as { todayPainted?: number }).todayPainted, null, { timeout: 30_000 });
    const ms = (await painted.jsonValue()) as number;
    testInfo.annotations.push({ type: 'cold start to Today', description: `${Math.round(ms)} ms` });

    expect(await page.evaluate(() => navigator.serviceWorker.controller !== null), 'the worker answered the start').toBe(true);
    expect(ms).toBeLessThan(1000);
});
