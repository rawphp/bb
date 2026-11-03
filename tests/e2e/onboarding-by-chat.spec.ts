import { expect, devices, test, type Browser, type Page } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { mkdirSync, rmSync, writeFileSync } from 'node:fs';
import type { OnboardingShowOutput } from '../../resources/js/contracts/onboarding_show';

// E2E_CHAT: a new account sets up by talking to the Coach (AGT-030 to AGT-033) and ends at Today with the plan the
// stepper builds from the same answers. The Coach is the scripted fake model of tests/Support/ScriptedCoach.php: the
// spec writes what it does for each message, and the e2e server (tests/e2e/router.php) turns the Coach on while that
// script exists. One account sets up in the stepper, the other in the chat, and the rows "Start my plan" wrote are
// the same for both.
//
// The plan starts tomorrow (ONB-020) for both accounts, on the same day.
// scripts/e2e-server.sh's MySQL listens on the app's port + 1.
const DB_PORT = String(Number(process.env.E2E_PORT ?? 8125) + 1);
const SCRIPT = 'storage/framework/testing/e2e-coach.json';
const DAY = new Date(Date.now() + 86_400_000).toISOString().slice(0, 10);
const WEEKDAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'] as const;
// Three training days, the plan's first day among them.
const FIRST = new Date(`${DAY}T12:00:00Z`).getUTCDay();
const TRAINING_DAYS = [0, 2, 4].map((offset) => WEEKDAYS[(FIRST + offset) % 7]!);
// ISO weekdays, as the Coach sends them: 1 is Monday, 7 is Sunday.
const isoWeekday = (name: string): number => WEEKDAYS.indexOf(name as (typeof WEEKDAYS)[number]) || 7;
const WEIGH_IN_DAYS = ['Monday', 'Wednesday', 'Friday', 'Saturday'];

type Script = Record<string, { calls?: { name: string; arguments: unknown }[]; reply: string }>;
type Setup = OnboardingShowOutput;

test.use({ timezoneId: 'UTC' });

// The script is on disk only while this spec runs: with it the e2e server's Coach is on, without it the other specs
// see an instance with no Coach.
const script: Script = {};
function say(message: string, calls: { name: string; arguments: unknown }[], reply: string): void {
    script[message] = { calls, reply };
    mkdirSync('storage/framework/testing', { recursive: true });
    writeFileSync(SCRIPT, JSON.stringify(script));
}
test.afterAll(() => rmSync(SCRIPT, { force: true }));

/** Rows from the server's database, with numbers as numbers. */
function rows<T>(sql: string, ...params: string[]): T[] {
    const php = [
        '$pdo = new PDO("mysql:host=127.0.0.1;port=".$argv[1].";dbname=bodybuilder", "root", "", [PDO::ATTR_EMULATE_PREPARES => false]);',
        '$st = $pdo->prepare($argv[2]); $st->execute(array_slice($argv, 3)); echo json_encode($st->fetchAll(PDO::FETCH_ASSOC));',
    ].join(' ');
    return JSON.parse(execFileSync('php', ['-r', php, '--', DB_PORT, sql, ...params], { encoding: 'utf8' })) as T[];
}

/** A phone-sized page of its own, so each account has its own session. */
async function newAccountPage(browser: Browser, baseURL: string): Promise<Page> {
    const context = await browser.newContext({ ...devices['Pixel 7'], viewport: { width: 390, height: 844 }, baseURL, timezoneId: 'UTC' });
    return context.newPage();
}

/** ACC-001: a new account, after the signed-out page settles (tests/e2e/support.ts says why). Returns its id. */
async function createAccount(page: Page, email: string): Promise<string> {
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

async function pick(page: Page, group: string, name: string): Promise<void> {
    await page.getByRole('radiogroup', { name: group, exact: true }).getByRole('radio', { name, exact: true }).click();
}

/** Types a field and leaves it: the step saves a typed answer when it loses focus. */
async function type(page: Page, label: string, value: string): Promise<void> {
    const field = page.getByLabel(label, { exact: true });
    await field.fill(value);
    await field.blur();
}

/** Each stepper step's answers, by its heading. Steps not listed keep their defaults. */
const ANSWERS: Record<string, (page: Page) => Promise<void>> = {
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
        for (const day of TRAINING_DAYS) await page.getByRole('button', { name: day, exact: true }).click();
    },
    'Weigh-ins': async (page) => {
        for (const day of WEIGH_IN_DAYS) await page.getByRole('button', { name: day, exact: true }).click();
    },
};

/** The rows "Start my plan" wrote, with everything that is the account's own or the moment's left out. */
const PLAN_TABLES = [
    ['profiles', 'user_id'],
    ['plans', 'user_id'],
    ['phases', 'start_date'],
    ['mesocycles', 'start_date'],
    ['plan_weeks', 'start_date'],
    ['day_targets', 'date'],
    ['weigh_in_schedule_entries', 'time'],
] as const;
const UUID = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

function plan(userId: string): Record<string, Record<string, unknown>[]> {
    // The ids the plan gives its own rows, named by where they first appear, so a foreign key still has to point at
    // the row it did for the other account.
    const named = new Map<string, string>();
    const name = (text: string): string => text.replace(new RegExp(UUID, 'g'), (id) => named.get(id) ?? (named.set(id, `<id ${named.size + 1}>`), named.get(id)!));
    return Object.fromEntries(
        PLAN_TABLES.map(([table, order]) => [
            table,
            rows<Record<string, unknown>>(`select * from ${table} where user_id = ? order by ${order}`, userId).map((row) =>
                Object.fromEntries(
                    Object.keys(row)
                        .sort()
                        .filter((column) => !['id', 'user_id', 'created_at', 'updated_at'].includes(column) || (column === 'id' && new RegExp(`^${UUID}$`).test(String(row.id))))
                        .map((column) => [column, JSON.parse(name(JSON.stringify(row[column])))]),
                ),
            ),
        ]),
    );
}

test('AGT-030 AGT-031 AGT-032 AGT-033 ONB-020 a new account sets up by chat with the Coach and gets the plan the stepper builds from the same answers', async ({ browser, baseURL }) => {
    // The Coach is on while the script exists.
    say('(the Coach is on)', [], 'On.');

    // AGT-030: the stepper account. With the Coach on, setup opens on two doors; this account takes the first.
    const stepper = await newAccountPage(browser, baseURL!);
    const stepperId = await createAccount(stepper, `chat-stepper-${Date.now()}@example.com`);
    await stepper.goto('/onboarding', { waitUntil: 'networkidle' });
    await expect(stepper.getByRole('button', { name: /Talk it through with the Coach/ })).toBeVisible();
    await stepper.getByRole('button', { name: /Set up step by step/ }).click();
    const visited: string[] = [];
    for (let step = 1; step <= 13; step++) {
        await expect(stepper.getByText(`Step ${step} of 13`, { exact: true })).toBeVisible();
        const heading = (await stepper.getByRole('heading', { level: 2 }).first().textContent())!.trim();
        visited.push(heading);
        await ANSWERS[heading]?.(stepper);
        await stepper.getByRole('button', { name: /^(Next|Continue)$/ }).click();
    }
    expect(visited).toEqual(expect.arrayContaining(Object.keys(ANSWERS)));
    await stepper.getByRole('button', { name: 'Start my plan' }).click();
    await stepper.waitForURL('**/today');
    expect(rows<{ start_date: string }>('select start_date from plans where user_id = ?', stepperId)).toEqual([{ start_date: DAY }]);
    const stepperPlan = plan(stepperId);

    // The chat account, on the same answers.
    const page = await newAccountPage(browser, baseURL!);
    const chatId = await createAccount(page, `chat-coach-${Date.now()}@example.com`);

    // What the page itself reads of setup, for what only the server knows (a default time, an option's value).
    let setup: Setup | null = null;
    page.on('response', (response) => {
        if (response.request().method() === 'POST' && response.url().endsWith('/capabilities/onboarding_show')) {
            void response.json().then((body: { data: Setup }) => (setup = body.data)).catch(() => undefined);
        }
    });
    const at = (key: Setup['step']['key']) => expect.poll(() => (setup as Setup | null)?.step.key).toBe(key);
    const shown = (): Setup => setup!;

    /** Types a message to the Coach, and waits for its reply to show. */
    const send = async (message: string, reply: string): Promise<void> => {
        await page.getByLabel('Message the Coach').fill(message);
        await page.locator('[data-test="send"]').click();
        await expect(page.locator('li[data-role="assistant"]').filter({ hasText: reply })).toBeVisible();
    };
    const openChat = async (): Promise<void> => {
        await page.goto('/onboarding', { waitUntil: 'networkidle' });
        await page.getByRole('button', { name: /Talk it through with the Coach/ }).click();
        await expect(page.getByLabel('Message the Coach')).toBeVisible();
    };

    // AGT-031: a few answers at a time, with onboarding_answer. Steps the stepper account left at their defaults
    // are passed over with no answer.
    await openChat();
    const next = { move: 'next' };
    const answer = (answers: unknown) => ({ name: 'onboarding_answer', arguments: { answers, ...next } });
    const first = 'I want to lose fat. I am New Lifter, male, born 10 May 1994, 180 cm and 90 kg. I have not dieted lately.';
    say(
        first,
        [
            answer({ goal: 'fatLoss', finished_diet_recently: false }),
            answer({ name: 'New Lifter', sex: 'male', birth_date: '1994-05-10', height: { unit: 'cm', cm: 180 }, weight: { unit: 'kg', value: 90 } }),
        ],
        'Next is a short health check. Answer it on the sheet that just opened.',
    );
    await send(first, 'Next is a short health check');
    await at('screening');

    // AGT-031 (SAFE-001): the health check is the user's, on its own sheet. Nothing is ticked; Done sends it, and the
    // chat tells the Coach where setup is now.
    const sheet = page.getByRole('dialog', { name: shown().step.title });
    await expect(sheet).toBeVisible();
    const afterCheck = `I've answered the health check. Setup is now at: ${visited[3]}`;
    const bodyFat = (): string => shown().body_fat.options.find((option) => option.title.includes('15-22%'))!.value;
    say(afterCheck, [answer({ uses_glp1: false })], 'Next is body fat.');
    await sheet.getByRole('button', { name: 'Done', exact: true }).click();
    await expect(page.locator('li[data-role="assistant"]').filter({ hasText: 'Next is body fat.' })).toBeVisible();
    await at('body_fat');

    const second = 'About 15 to 22 percent, intermediate.';
    say(second, [answer({ body_fat: bodyFat() }), answer({}), answer({ experience: 'intermediate' })], 'Now the days you train.');
    await send(second, 'Now the days you train.');
    await at('training_days');

    // AGT-033: leaving the chat opens the stepper at the step the chat reached, with the earlier answers kept.
    await page.getByRole('button', { name: 'Close', exact: true }).click();
    await expect(page.getByText('Step 8 of 13', { exact: true })).toBeVisible();
    await expect(page.getByRole('heading', { level: 2 }).first()).toHaveText(visited[7]!);
    await page.getByRole('button', { name: 'Back', exact: true }).click();
    await expect(page.getByRole('radiogroup', { name: 'Training experience', exact: true }).getByRole('radio', { name: 'Intermediate', exact: true })).toBeChecked();
    expect(rows<{ goal: string }>('select goal from onboarding_drafts where user_id = ?', chatId)).toMatchObject([{ goal: 'fatLoss' }]);

    // ...and the chat carries on from there.
    await openChat();
    const days = TRAINING_DAYS.map((day) => {
      const chosen = shown().training_days.days.find((entry) => entry.weekday === isoWeekday(day))!;
      return { weekday: isoWeekday(day), start_minutes: chosen.start.minutes, length_minutes: chosen.length_minutes };
    });
    const weighIns = WEIGH_IN_DAYS.map((day) => ({ weekday: isoWeekday(day), minutes: shown().weigh_ins.days.find((entry) => entry.weekday === isoWeekday(day))!.time.minutes }));
    const third = `I train ${TRAINING_DAYS.join(', ')}; weigh me in ${WEIGH_IN_DAYS.join(', ')}. Everything else as suggested.`;
    say(
        third,
        [answer({ training_days: days }), answer({}), answer({}), answer({}), answer({ weigh_ins: weighIns }), answer({})],
        'Here is your plan. Start it from the card, or tell me what to change.',
    );
    await send(third, 'Here is your plan');
    await at('review');

    // AGT-032: the review is a card in the chat, the same summary as the stepper's, and the plan starts on its button.
    const card = page.locator('[data-test="review-card"]');
    await expect(card).toBeVisible();
    const cardText = (await card.innerText()).replace(/\s+/g, ' ');
    for (const day of TRAINING_DAYS) expect(cardText).toContain(`${day.slice(0, 3)} 6:00 PM for 60 min`);
    say('confirm: onboarding_complete{}', [{ name: 'onboarding_complete', arguments: {} }], 'Your plan starts tomorrow. Want me to swap any exercises or move a training day?');
    await card.locator('[data-test="start-plan"]').click();

    // ONB-020: Today, with its tour, before the plan's first day.
    await page.waitForURL('**/today');
    const tour = page.getByRole('dialog', { name: 'Days and calories' });
    await expect(tour).toBeVisible();
    await tour.getByRole('button', { name: 'Skip' }).click();
    await expect(page.getByText(/Your plan starts/)).toBeVisible();
    expect(rows<{ start_date: string }>('select start_date from plans where user_id = ?', chatId)).toEqual([{ start_date: DAY }]);

    // AGT-030: both doors end in the same plan. Every table has rows, so the comparison proves something.
    const chatPlan = plan(chatId);
    for (const [table] of PLAN_TABLES) expect(chatPlan[table]!.length, table).toBeGreaterThan(0);
    expect(chatPlan).toEqual(stepperPlan);
});
