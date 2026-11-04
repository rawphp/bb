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

// Today's missed-day and welcome-back questions, added to the real today_show answer. The service
// worker is blocked so page.route sees the capability call.
const MISSED = {
    missed_session: {
        mesocycle_id: '1',
        week: 1,
        day: 1,
        title: 'You missed Monday',
        text: 'What should happen to it?',
        choices: [{ choice: 'skip', label: 'Skip it', date: null }],
    },
    missed_weeks: {
        mesocycle_id: '1',
        title: 'Welcome back',
        text: 'You have been away a while.',
        can_restart: true,
        end_warning: 'Ending it keeps your history.',
        offers_diet_break: false,
    },
};

async function askMissed(page: Page, which: keyof typeof MISSED): Promise<void> {
    await page.route('**/capabilities/today_show', async (route) => {
        const response = await route.fetch();
        const body = await response.json();
        body.data = { ...body.data, missed_session: null, missed_weeks: null, [which]: MISSED[which] };
        await route.fulfill({ response, json: body });
    });
}

async function sheetBox(page: Page, title: string) {
    const sheet = page.getByRole('dialog', { name: title });
    await expect(sheet).toBeVisible();
    // The sheet springs open; measure once it has settled.
    await expect(async () => {
        const first = (await sheet.boundingBox())!;
        await page.waitForTimeout(100);
        expect((await sheet.boundingBox())!).toEqual(first);
    }).toPass();
    return (await sheet.boundingBox())!;
}

test.describe('sheets', () => {
    test.use({ serviceWorkers: 'block' });

    for (const [which, title] of [
        ['missed_session', 'You missed Monday'],
        ['missed_weeks', 'Welcome back'],
    ] as const) {
        test(`PLT-002 puts Today's "${title}" sheet in the centred 40rem column at 1440 × 900`, { tag: '@desktop' }, async ({ page }) => {
            await askMissed(page, which);
            await openToday(page);
            const viewport = page.viewportSize()!;
            const rail = await box(page, 'nav[aria-label="Tabs"]');

            const sheet = await sheetBox(page, title);
            expect(sheet.width).toBeLessThanOrEqual(640);
            const spaceCentre = (rail.x + rail.width + viewport.width) / 2;
            expect(Math.abs(sheet.x + sheet.width / 2 - spaceCentre)).toBeLessThanOrEqual(1);
            expect(Math.abs(sheet.y + sheet.height - viewport.height)).toBeLessThanOrEqual(1);
        });
    }

    test('PLT-002 keeps sheets full width at 390 × 844', async ({ page }) => {
        await askMissed(page, 'missed_session');
        await openToday(page);
        const viewport = page.viewportSize()!;

        const sheet = await sheetBox(page, 'You missed Monday');
        expect(sheet.x).toBe(0);
        expect(sheet.width).toBe(390);
        expect(Math.abs(sheet.y + sheet.height - viewport.height)).toBeLessThanOrEqual(1);
    });
});
