import { expect, test, type Page } from '@playwright/test';
import { signIn } from './support';

// The rail's Back button (NAV-003). The @desktop test runs on the desktop project (1440 × 900);
// the other runs on the phone project (390 × 844), where the edge swipe goes back instead (PWA-022).

async function openFirstMeal(page: Page): Promise<void> {
    await signIn(page);
    await page.goto('/');
    await page.waitForURL('**/today');
    // ONB-030: a new device sees Today's tour first; it would cover the screen.
    const tour = page.getByRole('dialog', { name: 'Days and calories' });
    await expect(tour).toBeVisible();
    await tour.getByRole('button', { name: 'Skip' }).click();
    await expect(tour).toBeHidden();
    await page.locator('[data-test="meal-card"]').first().click();
    await expect(page).not.toHaveURL(/\/today$/);
}

test('NAV-003 the rail goes back from a pushed screen at 1440 × 900', { tag: '@desktop' }, async ({ page }) => {
    await openFirstMeal(page);
    const back = page.locator('nav[aria-label="Tabs"] button[data-back]');
    await expect(back).toBeVisible();
    await expect(back).toHaveAccessibleName('Back');

    await back.click();

    await expect(page).toHaveURL(/\/today$/);
    await expect(page.locator('[data-back]')).toHaveCount(0);
});

test('NAV-003 shows no Back button in the bottom bar at 390 × 844', async ({ page }) => {
    await openFirstMeal(page);

    await expect(page.locator('[data-back]')).toBeHidden();
    await expect(page.locator('nav[aria-label="Tabs"] [data-tab]')).toHaveCount(5);
});
