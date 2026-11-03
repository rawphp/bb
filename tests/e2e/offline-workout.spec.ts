import { expect, test, type Page } from '@playwright/test';
import { execFileSync, spawnSync } from 'node:child_process';
import { readFileSync } from 'node:fs';

// OFF-050: the whole journey on a phone. The account is Tests\Support\OfflineWorkoutSeeder's, seeded for the day the
// clock walks to: tomorrow on the server's UTC clock, five minutes before the 17:30 session, so the walk is always
// forward and a session of a few minutes ends far enough from its planned hour to re-target Dinner (SCH-054).
// scripts/e2e-server.sh's MySQL listens on the app's port + 1.
const DB_PORT = String(Number(process.env.E2E_PORT ?? 8125) + 1);
const DAY = new Date(Date.now() + 86_400_000).toISOString().slice(0, 10);
const WALKED_TO = Date.parse(`${DAY}T17:25:00Z`);

// The sets as typed, in the order the screen shows them: Bench Press, then Back Squat.
const TYPED = [
    { weight: 60, reps: 10 },
    { weight: 62.5, reps: 8 },
    { weight: 100, reps: 6 },
    { weight: 102.5, reps: 5 },
];

type Entry = { name: string; idempotencyKey: string; occurredAt: string; input: Record<string, unknown> };

test.use({ timezoneId: 'UTC' });

/** Runs artisan in the server's environment (scripts/e2e-server.sh). Artisan reports a failure on stdout. */
function artisan(...args: string[]): void {
    const run = spawnSync('php', ['artisan', ...args, '--no-interaction'], {
        env: {
            ...process.env,
            APP_ENV: 'local',
            DB_CONNECTION: 'mysql',
            DB_HOST: '127.0.0.1',
            DB_PORT,
            DB_DATABASE: 'bodybuilder',
            DB_USERNAME: 'root',
            DB_PASSWORD: '',
            SESSION_DRIVER: 'file',
            CACHE_STORE: 'file',
            QUEUE_CONNECTION: 'sync',
            MAIL_MAILER: 'array',
            BILLING_ENABLED: 'false',
            BB_E2E_DAY: DAY,
        },
        encoding: 'utf8',
    });
    if (run.status !== 0) throw new Error(`php artisan ${args.join(' ')} failed:\n${run.stdout}${run.stderr}`);
}

test.beforeAll(() => {
    // Every run that day walks to the same instant, so an earlier run's rate-limit counters in the file cache are
    // still live on the walked clock: the run starts with none.
    artisan('cache:clear');
    artisan('db:seed', '--class=Tests\\Support\\OfflineWorkoutSeeder', '--force');
});

/** Rows from the server's database, with numbers as numbers. */
function rows<T>(sql: string, ...params: string[]): T[] {
    const php = [
        '$pdo = new PDO("mysql:host=127.0.0.1;port=".$argv[1].";dbname=bodybuilder", "root", "", [PDO::ATTR_EMULATE_PREPARES => false]);',
        '$st = $pdo->prepare($argv[2]); $st->execute(array_slice($argv, 3)); echo json_encode($st->fetchAll(PDO::FETCH_ASSOC));',
    ].join(' ');
    return JSON.parse(execFileSync('php', ['-r', php, '--', DB_PORT, sql, ...params], { encoding: 'utf8' })) as T[];
}

/** The device's outbox, oldest first, read from IndexedDB as the app stored it. */
function outbox(page: Page): Promise<Entry[]> {
    return page.evaluate(
        () =>
            new Promise<Entry[]>((done, fail) => {
                const open = indexedDB.open('bodybuilder-outbox');
                open.onerror = () => fail(open.error);
                open.onsuccess = () => {
                    const all = open.result.transaction('entries').objectStore('entries').getAll();
                    all.onerror = () => fail(all.error);
                    all.onsuccess = () => {
                        open.result.close();
                        done(all.result as Entry[]);
                    };
                };
            }),
    );
}

/** A stored UTC datetime as the ISO instant it records, to the second. */
function instant(stored: string): string {
    return `${stored.replace(' ', 'T')}Z`;
}

function toSecond(iso: string): string {
    return `${new Date(iso).toISOString().slice(0, 19)}Z`;
}

test('OFF-050 OFF-001 a workout started online is logged, answered and finished offline, and the server holds every set once at the offline times', async ({ page, context }) => {
    const account = JSON.parse(readFileSync('storage/framework/testing/e2e-offline.json', 'utf8')) as { email: string; password: string; workout: string };

    // Online: sign in (after the signed-out page settles, as tests/e2e/support.ts does), let the worker take the shell,
    // and walk the clock to the seeded day.
    await page.goto('/sign-in', { waitUntil: 'networkidle' });
    await page.getByLabel('Email').fill(account.email);
    await page.getByLabel('Password', { exact: true }).fill(account.password);
    await page.getByRole('button', { name: 'Sign in', exact: true }).click();
    await page.waitForURL('**/today');
    await page.evaluate(() => navigator.serviceWorker.ready.then(() => undefined));
    // The device has seen Today's first-run tour, which is not this journey.
    await page.evaluate((offset) => {
        localStorage.setItem('bb.clock-offset', String(offset));
        localStorage.setItem('bb.today.tourSeen', '1');
    }, WALKED_TO - Date.now());
    await page.reload({ waitUntil: 'networkidle' });

    const dinner = page.locator('[data-test="meal-card"]').filter({ hasText: 'Dinner' });
    await expect(dinner).toBeVisible();
    const dinnerBefore = await dinner.innerText();

    // Start today's workout from Today, and let its screen load before the signal goes.
    const workout = page.locator('[data-test="workout-card"]').filter({ hasText: 'Full Body' });
    await workout.getByRole('button', { name: 'Start' }).click();
    await page.waitForURL(`**${account.workout}`);
    await expect(page.locator('[data-set]')).toHaveCount(TYPED.length);
    await page.waitForLoadState('networkidle');

    await context.setOffline(true);

    // Log every set; each muscle's feedback opens once its last set is in.
    const sets = page.locator('[data-set]');
    for (const [index, typed] of TYPED.entries()) {
        const set = sets.nth(index);
        await set.locator('[data-test="weight"]').fill(String(typed.weight));
        await set.locator('[data-test="reps"]').fill(String(typed.reps));
        await set.locator('[data-test="log"]').click();
        await expect(set.locator('[data-test="log"]')).toHaveAttribute('aria-checked', 'true');

        if (index % 2 === 1) {
            const sheet = page.getByRole('dialog');
            await expect(sheet).toBeVisible();
            for (const group of await sheet.getByRole('radiogroup').all()) await group.getByRole('radio').first().click();
            await sheet.getByRole('button', { name: 'Save', exact: true }).click();
            await expect(sheet).toBeHidden();
        }
    }

    await page.locator('[data-test="finish"]').click();
    await expect.poll(async () => (await outbox(page)).map((entry) => entry.name)).toContain('workout_finish');

    // Still offline, the page reloads from the worker's cache and the outbox: every typed set is still there.
    await page.reload();
    for (const [index, typed] of TYPED.entries()) {
        await expect(sets.nth(index).locator('[data-test="weight"]')).toHaveValue(String(typed.weight));
        await expect(sets.nth(index).locator('[data-test="reps"]')).toHaveValue(String(typed.reps));
    }
    // The outbox holds the start, each field as it was left and each Log tap, both muscles' answers and the finish.
    const queued = await outbox(page);
    const names = queued.map((entry) => entry.name);
    expect(names.at(0)).toBe('workout_start');
    expect(names.at(-1)).toBe('workout_finish');
    expect(queued.filter((entry) => entry.name === 'workout_feedback_answer').map((entry) => entry.input.muscle_group)).toEqual(['chest', 'quads']);
    const logs = queued.filter((entry) => entry.name === 'workout_set_log');
    const setIds = [...new Set(logs.map((entry) => String(entry.input.set_id)))];
    expect(setIds.map((id) => logs.filter((entry) => entry.input.set_id === id).at(-1)!.input)).toEqual(
        TYPED.map((typed, index) => ({ set_id: setIds[index], weight: { value: typed.weight, unit: 'kg' }, reps: typed.reps, is_logged: true })),
    );

    // Online again, two seconds on: a server that took its own clock would store other times than the user's. The
    // outbox replays, each entry with the time the user acted.
    await page.waitForTimeout(2_000);
    const sent = new Map<string, string | undefined>();
    page.on('request', (request) => {
        const key = request.headers()['idempotency-key'];
        if (key) sent.set(key, request.headers()['x-bb-occurred-at']);
    });
    await context.setOffline(false);
    await expect.poll(async () => (await outbox(page)).length, { timeout: 60_000 }).toBe(0);
    for (const entry of queued) expect(sent.get(entry.idempotencyKey), entry.name).toBe(entry.occurredAt);

    // The server holds one session, started and finished when the user did, and each set once, as typed.
    const [user] = rows<{ id: number }>('select id from users where email = ?', account.email);
    const sessions = rows<{ id: string; actual_start_at: string; actual_end_at: string }>(
        'select id, actual_start_at, actual_end_at from workout_sessions where user_id = ?',
        String(user.id),
    );
    expect(sessions).toHaveLength(1);
    const [session] = sessions;
    const at = (name: string) => toSecond(queued.find((entry) => entry.name === name)!.occurredAt);
    expect(instant(session.actual_start_at)).toBe(at('workout_start'));
    expect(instant(session.actual_end_at)).toBe(at('workout_finish'));

    const stored = rows<{ id: string; actual_weight_kg: number; actual_reps: number; is_logged: number; deleted_at: string | null }>(
        'select s.id, s.actual_weight_kg, s.actual_reps, s.is_logged, s.deleted_at from workout_sets s join workout_exercises e on e.id = s.workout_exercise_id where s.workout_session_id = ? order by e.position, s.position',
        session.id,
    );
    expect(stored.map(({ actual_weight_kg, actual_reps, is_logged, deleted_at }) => ({ weight: actual_weight_kg, reps: actual_reps, is_logged, deleted_at }))).toEqual(
        TYPED.map((typed) => ({ ...typed, is_logged: 1, deleted_at: null })),
    );
    expect(stored.map((set) => set.id)).toEqual(setIds);
    expect(rows('select muscle_group from muscle_feedback where workout_session_id = ? order by muscle_group', session.id)).toEqual([
        { muscle_group: 'chest' },
        { muscle_group: 'quads' },
    ]);

    // Today shows Dinner as the server re-targeted it for the session's real length (SCH-054).
    type Meal = { name: string; time: string; kcal: number; carbs_g: number };
    const [day] = rows<{ meals: string }>('select meals from day_targets where user_id = ? and date = ?', String(user.id), DAY);
    const retargeted = (JSON.parse(day.meals) as Meal[]).find((meal) => meal.name === 'Dinner')!;
    expect(retargeted).not.toMatchObject({ time: '19:00', kcal: 1120, carbs_g: 130 });
    await page.goto('/today', { waitUntil: 'networkidle' });
    await expect(dinner).not.toHaveText(dinnerBefore);
    await expect(dinner).toContainText(`${retargeted.kcal} kcal`);
    await expect(dinner).toContainText(`C ${retargeted.carbs_g}`);
});
