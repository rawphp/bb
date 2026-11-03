import { expect, test, type Page } from '@playwright/test';
import { signIn } from './support';

// The shell at a phone size and a desktop size (PLT-002, NAV-003). The phone test runs on the
// phone project (390 × 844); the @desktop test runs on the desktop project (1440 × 900).

async function openToday(page: Page): Promise<void> {
    await signIn(page);
    await page.goto('/');
    await page.waitForURL('**/today');
    await expect(page.getByText('Breakfast').first()).toBeVisible();
    // ONB-030: a new device sees Today's tour first; it would cover the tabs.
    const tour = page.getByRole('dialog', { name: 'Days and calories' });
    await expect(tour).toBeVisible();
    await tour.getByRole('button', { name: 'Skip' }).click();
    await expect(tour).toBeHidden();
}

async function box(page: Page, selector: string) {
    const found = await page.locator(selector).first().boundingBox();
    expect(found, selector).not.toBeNull();
    return found!;
}

async function tabBoxes(page: Page) {
    const tabs = page.locator('nav[aria-label="Tabs"] [data-tab]');
    await expect(tabs).toHaveCount(5);
    return Promise.all((await tabs.all()).map(async (tab) => (await tab.boundingBox())!));
}

async function selectTrain(page: Page): Promise<void> {
    const train = page.locator('nav[aria-label="Tabs"] [data-tab="train"]');
    await train.click();
    await expect(train).toHaveAttribute('aria-current', 'page');
}

test('PLT-002 NAV-003 shows a full-height left rail and a centred 40rem screen at 1440 × 900', { tag: '@desktop' }, async ({ page }) => {
    await openToday(page);
    const viewport = page.viewportSize()!;
    const nav = page.locator('nav[aria-label="Tabs"]');
    await expect(nav).toBeVisible();
    await expect(page.locator('nav[aria-label="Tabs"]')).toHaveCount(1);

    const rail = await box(page, 'nav[aria-label="Tabs"]');
    expect(rail.x).toBe(0);
    expect(rail.height).toBeGreaterThanOrEqual(viewport.height * 0.9);
    expect(rail.width).toBeLessThan(120);

    const tabs = await tabBoxes(page);
    for (let i = 1; i < tabs.length; i++) expect(tabs[i]!.y).toBeGreaterThan(tabs[i - 1]!.y);

    expect((await box(page, '#app')).width).toBe(1440);

    const screen = await box(page, '.bb-tab-stack--active > .bb-screen[data-top] > *');
    expect(screen.width).toBeLessThanOrEqual(640);
    const spaceCentre = (rail.x + rail.width + viewport.width) / 2;
    expect(Math.abs(screen.x + screen.width / 2 - spaceCentre)).toBeLessThanOrEqual(1);

    await selectTrain(page);
});

test('PLT-002 keeps the bottom tab bar and the phone column at 390 × 844', async ({ page }) => {
    await openToday(page);
    const viewport = page.viewportSize()!;
    await expect(page.locator('nav[aria-label="Tabs"]')).toBeVisible();

    const bar = await box(page, 'nav[aria-label="Tabs"]');
    expect(bar.width).toBe(390);
    expect(Math.abs(bar.y + bar.height - viewport.height)).toBeLessThanOrEqual(1);

    const tabs = await tabBoxes(page);
    for (const tab of tabs) expect(Math.abs(tab.y - tabs[0]!.y)).toBeLessThanOrEqual(1);

    expect((await box(page, '#app')).width).toBe(390);

    await selectTrain(page);
});
