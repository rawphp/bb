import { expect, test, type Page, type Request } from '@playwright/test';
import { execFileSync } from 'node:child_process';

// E2E_ONB: a new account goes through stepper onboarding (ONB), sees Today, then on the plan's first day starts the
// workout, logs every set, answers each muscle's feedback and finishes (WO). The server must hold every set and
// Today's meals re-targeted for the session as it happened (SCH-052, SCH-054).
//
// The plan starts tomorrow (ONB-020), so the clock walks there (SET-011): to 07:30 UTC, always forward, and far from
// the 18:00 session the account plans, so starting early re-targets the meals still to come.
// scripts/e2e-server.sh's MySQL listens on the app's port + 1.
const DB_PORT = String(Number(process.env.E2E_PORT ?? 8125) + 1);
const DAY = new Date(Date.now() + 86_400_000).toISOString().slice(0, 10);
const WALKED_TO = Date.parse(`${DAY}T07:30:00Z`);
const WEEKDAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'] as const;
// Three training days, the plan's first day among them.
const FIRST = new Date(`${DAY}T12:00:00Z`).getUTCDay();
const TRAINING_DAYS = [0, 2, 4].map((offset) => WEEKDAYS[(FIRST + offset) % 7]!);
const WEIGHT = 20;
const REPS = 10;

type Meal = { name: string; time: string; kcal: number; carbs_g: number };
type Feedback = { muscle_group: string; is_due: boolean; is_answered: boolean };

test.use({ timezoneId: 'UTC' });

/** Rows from the server's database, with numbers as numbers. */
function rows<T>(sql: string, ...params: string[]): T[] {
    const php = [
        '$pdo = new PDO("mysql:host=127.0.0.1;port=".$argv[1].";dbname=bodybuilder", "root", "", [PDO::ATTR_EMULATE_PREPARES => false]);',
        '$st = $pdo->prepare($argv[2]); $st->execute(array_slice($argv, 3)); echo json_encode($st->fetchAll(PDO::FETCH_ASSOC));',
    ].join(' ');
    return JSON.parse(execFileSync('php', ['-r', php, '--', DB_PORT, sql, ...params], { encoding: 'utf8' })) as T[];
}

function plannedMeals(userId: string): Meal[] {
    const day = rows<{ meals: string }>('select meals from day_targets where user_id = ? and date = ?', userId, DAY)[0]!;
    return JSON.parse(day.meals) as Meal[];
}

function isCall(name: string, request: Request): boolean {
    return request.method() === 'POST' && request.url().endsWith(`/capabilities/${name}`);
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

/** Each step's answers, by its heading. Steps not listed keep their defaults. */
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
        for (const day of ['Monday', 'Wednesday', 'Friday', 'Saturday']) await page.getByRole('button', { name: day, exact: true }).click();
    },
};

test('ONB-020 WO-010 SCH-052 a new account onboards, starts its first workout, logs every set, answers the feedback and finishes', async ({ page }) => {
    const email = `first-workout-${Date.now()}@example.com`;

    // ACC-001: a new account, after the signed-out page settles (tests/e2e/support.ts says why).
    await page.goto('/sign-in', { waitUntil: 'networkidle' });
    await page.getByRole('button', { name: 'New here? Create an account' }).click();
    await page.getByLabel('Name').fill('New Lifter');
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password', { exact: true }).fill('a-long-password-1');
    await page.getByLabel('Confirm password').fill('a-long-password-1');
    await page.getByRole('button', { name: 'Create account', exact: true }).click();
    await page.waitForURL('**/today');

    // ONB-002 to ONB-018: the thirteen steps, then the review. Nothing sends a new account to setup yet (the
    // SetupGate in docs/spec/19-views.md), so the journey opens it.
    await page.goto('/onboarding', { waitUntil: 'networkidle' });
    for (let step = 1; step <= 13; step++) {
        await expect(page.getByText(`Step ${step} of 13`, { exact: true })).toBeVisible();
        const heading = (await page.getByRole('heading', { level: 2 }).first().textContent())!.trim();
        await ANSWERS[heading]?.(page);
        await page.getByRole('button', { name: /^(Next|Continue)$/ }).click();
    }
    const review = page.getByRole('group', { name: 'Review your plan' });
    await expect(review).toBeVisible();
    const summary = (await review.innerText()).replace(/\s+/g, ' ');
    for (const day of TRAINING_DAYS) expect(summary).toContain(`${day.slice(0, 3)} 6:00 PM for 60 min`);
    await page.getByRole('button', { name: 'Start my plan' }).click();

    // ONB-020, ONB-030, ONB-031: Today, with its tour, before the plan's first day.
    await page.waitForURL('**/today');
    const tour = page.getByRole('dialog', { name: 'Days and calories' });
    await expect(tour).toBeVisible();
    await tour.getByRole('button', { name: 'Skip' }).click();
    await expect(page.getByText(/Your plan starts/)).toBeVisible();

    // The plan's first day, a training day.
    await page.evaluate((offset) => localStorage.setItem('bb.clock-offset', String(offset)), WALKED_TO - Date.now());
    await page.reload({ waitUntil: 'networkidle' });
    const workoutCard = page.locator('[data-test="workout-card"]');
    await expect(workoutCard).toContainText('Week 1 · Day 1');
    const user = rows<{ id: number }>('select id from users where email = ?', email)[0]!;
    const userId = String(user.id);
    const mealsBefore = plannedMeals(userId);

    // Every workout_show requested, and how many there were when the latest Log tap was answered.
    const reads: Request[] = [];
    let readsAtLog = -1;
    page.on('request', (request) => {
        if (isCall('workout_show', request)) reads.push(request);
    });
    page.on('response', (response) => {
        if (isCall('workout_set_log', response.request()) && (JSON.parse(response.request().postData()!) as { is_logged: boolean }).is_logged) {
            readsAtLog = reads.length;
        }
    });

    await workoutCard.getByRole('button', { name: 'Start' }).click();
    await page.waitForURL('**/workouts/**');
    const sets = page.locator('[data-set]');
    await expect(sets.first()).toBeVisible();
    const setCount = await sets.count();
    expect(setCount).toBeGreaterThan(0);

    // WO-001 to WO-010, FB-001: each set typed and logged. The next set waits for the read that follows the Log tap,
    // as a person does: a field left while an older read is still coming back sends the set unlogged again.
    const answered: string[] = [];
    for (let index = 0; index < setCount; index++) {
        const set = sets.nth(index);
        await set.locator('[data-test="weight"]').fill(String(WEIGHT));
        await set.locator('[data-test="reps"]').fill(String(REPS));
        readsAtLog = -1;
        await set.locator('[data-test="log"]').click();
        await expect.poll(() => readsAtLog >= 0 && reads.length > readsAtLog).toBe(true);
        const shown = (await (await reads[readsAtLog]!.response())!.json()) as { data: { feedback: Feedback[] } };
        await expect(set.locator('[data-test="log"]')).toHaveAttribute('aria-checked', 'true');

        const due = shown.data.feedback.filter((entry) => entry.is_due && !entry.is_answered);
        if (due.length === 0) continue;
        const sheet = page.getByRole('dialog');
        await expect(sheet).toBeVisible();
        for (const group of await sheet.getByRole('radiogroup').all()) await group.getByRole('radio').first().click();
        await sheet.getByRole('button', { name: 'Save', exact: true }).click();
        await expect(sheet).toBeHidden();
        answered.push(due[0]!.muscle_group);
    }

    const finished = page.waitForResponse((response) => isCall('workout_finish', response.request()));
    await page.locator('[data-test="finish"]').click();
    expect((await finished).status()).toBe(200);

    // The server holds the session finished, every set once as typed and logged, and each muscle's feedback.
    const sessions = rows<{ id: string; actual_end_at: string | null }>(
        'select id, actual_end_at from workout_sessions where user_id = ? and actual_start_at is not null',
        userId,
    );
    expect(sessions).toHaveLength(1);
    const session = sessions[0]!;
    expect(session.actual_end_at).not.toBeNull();
    const stored = rows<{ actual_weight_kg: number; actual_reps: number; is_logged: number; deleted_at: string | null }>(
        'select s.actual_weight_kg, s.actual_reps, s.is_logged, s.deleted_at from workout_sets s where s.workout_session_id = ?',
        session.id,
    );
    expect(stored).toEqual(Array.from({ length: setCount }, () => ({ actual_weight_kg: WEIGHT, actual_reps: REPS, is_logged: 1, deleted_at: null })));
    const feedback = rows<{ muscle_group: string }>('select muscle_group from muscle_feedback where workout_session_id = ?', session.id);
    expect(answered.length).toBeGreaterThan(0);
    expect(feedback.map((row) => row.muscle_group).sort()).toEqual([...answered].sort());

    // SCH-052, SCH-054: the meals still to come follow the session as it happened, and Today shows them.
    const mealsAfter = plannedMeals(userId);
    expect(mealsAfter).not.toEqual(mealsBefore);
    const retargeted = mealsAfter.filter((meal) => {
        const before = mealsBefore.find((planned) => planned.name === meal.name);
        return before === undefined || before.time !== meal.time || before.kcal !== meal.kcal || before.carbs_g !== meal.carbs_g;
    });
    expect(retargeted.length).toBeGreaterThan(0);
    await page.goto('/today', { waitUntil: 'networkidle' });
    for (const meal of retargeted) {
        const card = page.locator('[data-test="meal-card"]').filter({ hasText: meal.name });
        await expect(card).toContainText(`${Math.round(meal.kcal)} kcal`);
        await expect(card).toContainText(`C ${Math.round(meal.carbs_g)}`);
    }
});
