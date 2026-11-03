import { expect, type Page } from '@playwright/test';
import { readFileSync } from 'node:fs';

/** What Tests\Support\AuditSeeder wrote: the account and the paths of its Today, workout and meal. */
export type Seeded = { email: string; password: string; today: string; workout: string; meal: string };

export function seeded(): Seeded {
    return JSON.parse(readFileSync('storage/framework/testing/e2e.json', 'utf8')) as Seeded;
}

/**
 * Signs in through the sign-in screen and waits for Today's seeded breakfast. It waits for the
 * page's signed-out requests first: one answered after the sign-in would set the guest session's
 * cookie again.
 */
export async function signIn(page: Page): Promise<void> {
    const account = seeded();
    await page.goto('/sign-in', { waitUntil: 'networkidle' });
    await page.getByLabel('Email').fill(account.email);
    await page.getByLabel('Password', { exact: true }).fill(account.password);
    await page.getByRole('button', { name: 'Sign in', exact: true }).click();
    await page.waitForURL('**/today');
    await expect(page.getByText('Breakfast').first()).toBeVisible();
}
