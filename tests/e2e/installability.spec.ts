import { expect, test } from '@playwright/test';

test('PWA-051 Chrome reports the manifest with no installability errors', async ({ page }) => {
    await page.goto('/sign-in');
    await page.evaluate(() => navigator.serviceWorker.ready.then(() => undefined));
    const cdp = await page.context().newCDPSession(page);

    const manifest = await cdp.send('Page.getAppManifest');
    const installability = await cdp.send('Page.getInstallabilityErrors');

    expect(manifest.url).toMatch(/\/manifest\.webmanifest$/);
    expect(manifest.errors).toEqual([]);
    expect(installability.installabilityErrors).toEqual([]);
});
