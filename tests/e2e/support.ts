import { expect, type Locator, type Page } from '@playwright/test';
import { execFileSync } from 'node:child_process';
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

/** Rows from the server's database, with numbers as numbers. scripts/e2e-server.sh's MySQL listens on the app's port + 1. */
export function rows<T>(sql: string, ...params: string[]): T[] {
    const port = String(Number(process.env.E2E_PORT ?? 8125) + 1);
    const php = [
        '$pdo = new PDO("mysql:host=127.0.0.1;port=".$argv[1].";dbname=bodybuilder", "root", "", [PDO::ATTR_EMULATE_PREPARES => false]);',
        '$st = $pdo->prepare($argv[2]); $st->execute(array_slice($argv, 3)); echo json_encode($st->fetchAll(PDO::FETCH_ASSOC));',
    ].join(' ');
    return JSON.parse(execFileSync('php', ['-r', php, '--', port, sql, ...params], { encoding: 'utf8' })) as T[];
}

/** ACC-001: a new account, after the signed-out page settles (signIn says why). Returns its id. */
export async function createAccount(page: Page, email: string): Promise<string> {
    await page.goto('/sign-in', { waitUntil: 'networkidle' });
    await page.getByRole('button', { name: 'New here? Create an account' }).click();
    await page.getByLabel('Name').fill('New Lifter');
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password', { exact: true }).fill('a-long-password-1');
    await page.getByLabel('Confirm password').fill('a-long-password-1');
    await page.getByRole('button', { name: 'Create account', exact: true }).click();
    await page.waitForURL('**/today');
    return String(rows<{ id: number }>('select id from users where email = ?', email)[0]!.id);
}

export async function pick(page: Page, group: string, name: string): Promise<void> {
    await page.getByRole('radiogroup', { name: group, exact: true }).getByRole('radio', { name, exact: true }).click();
}

/** Types a field and leaves it: the step saves a typed answer when it loses focus. */
export async function type(page: Page, label: string, value: string): Promise<void> {
    const field = page.getByLabel(label, { exact: true });
    await field.fill(value);
    await field.blur();
}

/** ONB-002 to ONB-017: each stepper step's answers, by its heading. Steps not listed keep their defaults. */
export function stepperAnswers(trainingDays: readonly string[], weighInDays: readonly string[]): Record<string, (page: Page) => Promise<void>> {
    return {
        "What's your goal?": async (page) => {
            await pick(page, 'Goal', 'Fat loss');
            await pick(page, 'Have you finished a fat-loss diet recently?', 'No');
        },
        'About you': async (page) => {
            await type(page, 'Name', 'New Lifter');
            await pick(page, 'Sex', 'Male');
            await page.getByRole('button', { name: 'Set date of birth' }).click();
            await type(page, 'Date of birth', '1994-05-10');
            await type(page, 'Height in cm', '180');
            await type(page, 'Weight in kg', '90');
        },
        'Weight-loss drugs': (page) => pick(page, 'Do you take a GLP-1 or similar weight-loss drug?', 'No'),
        'Body fat': (page) => page.getByRole('button', { name: 'Body fat 15-22%' }).click(),
        'Training experience': (page) => pick(page, 'Training experience', 'Intermediate'),
        'Training days': async (page) => {
            for (const day of trainingDays) await page.getByRole('button', { name: day, exact: true }).click();
        },
        'Weigh-ins': async (page) => {
            for (const day of weighInDays) await page.getByRole('button', { name: day, exact: true }).click();
        },
    };
}

/** The thirteen stepper steps, each answered by its heading, ending on the review. Returns the headings in order. */
export async function stepThroughSetup(page: Page, answers: Record<string, (page: Page) => Promise<void>>): Promise<string[]> {
    const visited: string[] = [];
    for (let step = 1; step <= 13; step++) {
        await expect(page.getByText(`Step ${step} of 13`, { exact: true })).toBeVisible();
        const heading = (await page.getByRole('heading', { level: 2 }).first().textContent())!.trim();
        visited.push(heading);
        await answers[heading]?.(page);
        await page.getByRole('button', { name: /^(Next|Continue)$/ }).click();
    }
    expect(visited).toEqual(expect.arrayContaining(Object.keys(answers)));
    return visited;
}

/** ONB-018: the review's sections, one string each, without the stepper's Edit links, so the stepper and the chat card compare. */
export async function reviewSections(scope: Locator): Promise<string[]> {
    const texts = await scope.locator('[data-test="review-section"]').allInnerTexts();
    return texts.map((text) =>
        text
            .split('\n')
            .map((line) => line.trim())
            .filter((line) => line !== '' && line !== 'Edit')
            .join(' '),
    );
}
