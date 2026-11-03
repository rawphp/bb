import { flushPromises, mount, RouterLinkStub, type VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { CapabilityError, read, writeNow } from '@/app/call';
import type { MealShowOutput } from '@/contracts/meal_show';
import CopySheet from '@/features/copy/CopySheet.vue';
import SavedMealPickerSheet from '@/features/saved-meals/SavedMealPickerSheet.vue';
import ScanView from '@/features/scan/ScanView.vue';
import StatusPill from '@/design/StatusPill.vue';
import { inputViolations, screenFixture } from '@/testing/schemas';
import MealAmountSheet from './MealAmountSheet.vue';
import MealFoodRowView from './MealFoodRowView.vue';
import MealRecipeRowView from './MealRecipeRowView.vue';
import MealView from './MealView.vue';

vi.mock('@/app/call', async (original) => ({ ...(await original<typeof import('@/app/call')>()), read: vi.fn(), readFresh: vi.fn(), writeNow: vi.fn() }));
const back = vi.fn();
vi.mock('vue-router', async (original) => ({ ...(await original<typeof import('vue-router')>()), useRouter: () => ({ back }) }));

const readMock = vi.mocked(read);
const writeMock = vi.mocked(writeNow);
const content = screenFixture<MealShowOutput>('meal_show');
const empty = screenFixture<MealShowOutput>('meal_show.empty');
const short = screenFixture<MealShowOutput>('meal_show.short');
const MEAL = content.meal_id;
const [chicken, rice, , oats] = content.entries;

let wrapper: VueWrapper | undefined;

function show(props: { payload?: MealShowOutput; mealId?: string; pending?: boolean } = {}): VueWrapper {
    wrapper = mount(MealView, {
        props,
        attachTo: document.body,
        global: { stubs: { RouterLink: RouterLinkStub, CopySheet: true, ScanView: true, SavedMealPickerSheet: true } },
    });
    return wrapper;
}

function links(view: VueWrapper): unknown[] {
    return view.findAllComponents(RouterLinkStub).map((link) => link.props('to'));
}

function row(view: VueWrapper, name: string): VueWrapper {
    return [...view.findAllComponents(MealFoodRowView), ...view.findAllComponents(MealRecipeRowView)].find((r) => r.get('h3').text() === name)! as VueWrapper;
}

async function menu(view: VueWrapper, item: string): Promise<void> {
    await view.get('button[aria-label="Meal menu"]').trigger('click');
    await view.get(`[data-test="item-${item}"]`).trigger('click');
    await flushPromises();
}

function dialog(): HTMLElement | null {
    return document.body.querySelector<HTMLElement>('[role="dialog"]');
}

async function type(name: string, value: string): Promise<void> {
    const field = document.body.querySelector<HTMLInputElement>(`[role="dialog"] input[name="${name}"]`)!;
    field.value = value;
    field.dispatchEvent(new Event('input'));
    await flushPromises();
}

async function saveSheet(): Promise<void> {
    document.body.querySelector<HTMLButtonElement>('[role="dialog"] [data-save]')!.click();
    await flushPromises();
}

/** The one write sent, checked against its schema snapshot. */
function sent(name: string): Record<string, unknown> {
    expect(writeMock).toHaveBeenCalledTimes(1);
    const [called, input, options] = writeMock.mock.calls[0]!;
    expect(called).toBe(name);
    expect(options).toEqual({ idempotencyKey: expect.stringMatching(new RegExp(`^${name}:`)) });
    expect(inputViolations(name, input)).toEqual([]);
    return input as Record<string, unknown>;
}

describe('MealView', () => {
    beforeEach(() => {
        readMock.mockReset().mockResolvedValue(content);
        writeMock.mockReset().mockResolvedValue({});
        back.mockReset();
    });
    afterEach(() => {
        wrapper?.unmount();
        wrapper = undefined;
        document.body.innerHTML = '';
    });

    describe('content', () => {
        it("MEAL-001 shows the meal's four macro bars and its foods, without calling the server", () => {
            const view = show({ payload: content });

            expect(view.get('h1').text()).toBe('Dinner');
            const bars = view.findAll('[role="progressbar"]');
            expect(bars.map((bar) => bar.attributes('aria-label'))).toEqual(['Calories', 'Protein', 'Fat', 'Carbs']);
            expect(bars[0]!.attributes('aria-valuetext')).toBe('1040 of 1040');
            expect(view.findAllComponents(MealFoodRowView).map((r) => r.text())).toEqual([
                expect.stringContaining('Chicken breast'),
                expect.stringContaining('White rice'),
                expect.stringContaining('Olive oil'),
            ]);
            expect(view.findAllComponents(MealRecipeRowView).map((r) => r.text())).toEqual([expect.stringContaining('Overnight oats')]);
            expect(readMock).not.toHaveBeenCalled();
        });

        it("MEAL-001 a food shows the server's amount and macros, and Cooked weight when it is weighed cooked", () => {
            const view = show({ payload: content });

            expect(row(view, 'Chicken breast').get('[data-test="amount"]').text()).toBe('296 g (about 2 fillets)');
            expect(row(view, 'Chicken breast').get('[data-test="macros"]').text()).toMatch(/355 kcal\s*P 68\s*F 6\s*C 0/);
            expect(row(view, 'Chicken breast').text()).not.toContain('Cooked weight');
            expect(row(view, 'White rice').text()).toContain('Cooked weight');
        });

        it('RCP-001 a recipe shows its servings and macros, marked Recipe', () => {
            const recipe = row(show({ payload: content }), 'Overnight oats');

            expect(recipe.text()).toContain('Recipe');
            expect(recipe.get('[data-test="amount"]').text()).toContain('1.5 servings');
            expect(recipe.get('[data-test="macros"]').text()).toContain('283 kcal');
        });

        it('MEAL-005 a locked food says so on its lock', () => {
            const view = show({ payload: content });

            const locked = row(view, 'Chicken breast').get('[data-test="lock"]');
            expect(locked.attributes('aria-label')).toBe('Unlock Chicken breast');
            expect(locked.attributes('aria-pressed')).toBe('true');
            expect(row(view, 'White rice').get('[data-test="lock"]').attributes('aria-label')).toBe('Lock White rice');
        });

        it('MEAL-003 MEAL-011 shows Targets met and the calorie and protein pills from the server', () => {
            const view = show({ payload: content });

            const summary = view.get('[data-test="summary"]').text();
            expect(summary).toContain('Targets met');
            expect(summary).toContain('Calories on track');
            expect(summary).toContain('Protein on track');
            expect(summary).toContain('6:30 PM');
        });

        it("MEAL-010 shows the peri-workout tag with the workout's time and the help line", () => {
            const tag = show({ payload: content }).get('[data-test="peri-workout"]');

            expect(tag.text()).toContain('Post-workout · Workout 5:30 PM');
            expect(tag.text()).toContain("These meals carry most of the day's carbs.");
        });

        it('MEAL-010 shows no tag for a meal away from the workout', () => {
            expect(show({ payload: empty }).find('[data-test="peri-workout"]').exists()).toBe(false);
        });

        it('UI-021 food search opens the Pantry for this meal, and nothing is in the way above it', () => {
            const view = show({ payload: content });

            expect(view.getComponent<typeof RouterLinkStub>('[data-test="search"]').props('to')).toEqual({ path: '/nutrition/pantry', query: { meal: MEAL } });
            expect(view.get('[data-test="search"]').attributes('aria-label')).toBe('Search foods');
            const order = [...view.element.querySelectorAll('[data-test="search"], [data-test="summary"], ul[aria-label="Foods"]')].map((el) => el.getAttribute('data-test') ?? 'foods');
            expect(order).toEqual(['search', 'summary', 'foods']);
        });

        it('UI-021 the camera button opens the scanner over this meal, and a scan that added foods reads the meal again', async () => {
            const view = show({ payload: content });
            expect(view.findComponent(ScanView).exists()).toBe(false);

            await view.get('button[aria-label="Scan a food"]').trigger('click');
            const scanner = view.findComponent(ScanView);
            expect(scanner.props('mealId')).toBe(MEAL);

            scanner.vm.$emit('close', true);
            await flushPromises();
            expect(view.findComponent(ScanView).exists()).toBe(false);
            expect(readMock).toHaveBeenCalledWith('meal_show', { meal_id: MEAL }, expect.any(Function), expect.any(Function));
        });

        it('UI-021 a scanner closed with nothing added does not read the meal again', async () => {
            const view = show({ payload: content });
            await view.get('button[aria-label="Scan a food"]').trigger('click');

            view.findComponent(ScanView).vm.$emit('close', false);
            await flushPromises();

            expect(readMock).not.toHaveBeenCalled();
        });
    });

    describe('empty', () => {
        it('MEAL-046 an empty meal says it is eaten at target, and Rebalance is off', () => {
            const view = show({ payload: empty });

            const note = view.get('[data-test="empty"]').text();
            expect(note).toContain('Your meal is empty');
            expect(note).toContain('Because this meal has no foods in it, the app assumes you are eating this meal at target macros.');
            expect(view.findAllComponents(MealFoodRowView)).toHaveLength(0);
            expect(view.get<HTMLButtonElement>('[data-test="rebalance"]').element.disabled).toBe(true);
        });

        it('MEAL-046 a meal with foods has no empty note', () => {
            expect(show({ payload: content }).find('[data-test="empty"]').exists()).toBe(false);
        });
    });

    describe('short', () => {
        it('MEAL-007 says which macro is short and opens the Pantry on that group', () => {
            const view = show({ payload: short });

            const hints = view.findAll('[data-test="hint"]');
            expect(hints).toHaveLength(1);
            expect(hints[0]!.text()).toContain(short.hints[0]!.text);
            expect(links(view)).toContainEqual({ path: '/nutrition/pantry', query: { meal: short.meal_id, group: short.hints[0]!.group } });
        });

        it('DAY-003 shows the Adjust foods badge, and no Targets met', () => {
            const summary = show({ payload: short }).get('[data-test="summary"]').text();

            expect(summary).toContain('Adjust foods');
            expect(summary).not.toContain('Targets met');
        });
    });

    describe('loading and error', () => {
        it('CAP-004 shows a skeleton while it reads meal_show for its meal, then the meal', async () => {
            const view = show({ mealId: MEAL });

            expect(view.find('[data-test="skeleton"]').exists()).toBe(true);
            expect(view.find('[data-test="rebalance"]').exists()).toBe(false);
            await flushPromises();

            expect(readMock).toHaveBeenCalledWith('meal_show', { meal_id: MEAL }, expect.any(Function), expect.any(Function));
            expect(view.find('[data-test="skeleton"]').exists()).toBe(false);
            expect(view.text()).toContain('Chicken breast');
        });

        it('CAP-004 says why the meal did not load, and Try again reads it again', async () => {
            readMock.mockReset().mockRejectedValueOnce(new CapabilityError('meal_show', 500, null)).mockResolvedValueOnce(content);
            const view = show({ mealId: MEAL });
            await flushPromises();

            expect(view.get('[role="alert"]').text()).toContain('The meal could not be loaded.');
            expect(view.get<HTMLButtonElement>('button[aria-label="Meal menu"]').element.disabled).toBe(true);
            await view.get('[data-test="retry"]').trigger('click');
            await flushPromises();
            expect(view.text()).toContain('Olive oil');
        });
    });

    describe('pending', () => {
        it("OFF-008 marks the bars, pills and each food's macros as waiting to sync", () => {
            const view = show({ payload: content, pending: true });

            const bars = view.findAll('[role="progressbar"]');
            expect(bars).toHaveLength(4);
            expect(bars.every((bar) => bar.attributes('aria-valuetext')!.includes('waiting to sync'))).toBe(true);
            const macros = view.findAll('[data-test="macros"]');
            expect(macros).toHaveLength(4);
            expect(macros.every((line) => line.text().includes('Waiting to sync'))).toBe(true);
            const pills = view.findAllComponents(StatusPill);
            expect(pills).toHaveLength(3);
            expect(pills.every((pill) => pill.text().includes('Waiting to sync'))).toBe(true);
        });

        it('OFF-008 marks nothing when nothing waits to sync', () => {
            expect(show({ payload: content }).text()).not.toContain('Waiting to sync');
        });
    });

    describe('writes', () => {
        it('MEAL-005 the lock sends the other lock state, then reads the meal again', async () => {
            const view = show({ payload: content });

            await row(view, 'Chicken breast').get('[data-test="lock"]').trigger('click');
            await flushPromises();

            expect(sent('meal_food_lock_set')).toEqual({ meal_id: MEAL, entry_id: chicken!.id, locked: false });
            expect(readMock).toHaveBeenCalledWith('meal_show', { meal_id: MEAL }, expect.any(Function), expect.any(Function));
        });

        it('RCP-002 MEAL-005 a recipe locks like a food', async () => {
            const view = show({ payload: content });

            const lock = row(view, 'Overnight oats').get('[data-test="lock"]');
            expect(lock.attributes('aria-label')).toBe('Lock Overnight oats');
            await lock.trigger('click');
            await flushPromises();

            expect(sent('meal_food_lock_set')).toEqual({ meal_id: MEAL, entry_id: oats!.id, locked: true });
        });

        it('MEAL-004 + steps a food up', async () => {
            const view = show({ payload: content });

            await row(view, 'White rice').get('[data-test="plus"]').trigger('click');
            await flushPromises();

            expect(sent('meal_food_step')).toEqual({ meal_id: MEAL, entry_id: rice!.id, direction: 'up' });
        });

        it("RCP-002 − steps a recipe's servings down", async () => {
            const view = show({ payload: content });

            await row(view, 'Overnight oats').get('[data-test="minus"]').trigger('click');
            await flushPromises();

            expect(sent('meal_food_step')).toEqual({ meal_id: MEAL, entry_id: oats!.id, direction: 'down' });
        });

        it("MEAL-008 a food's swipe action deletes it", async () => {
            const view = show({ payload: content });

            await row(view, 'White rice').get('[data-test="delete"]').trigger('click');
            await flushPromises();

            expect(sent('meal_food_remove')).toEqual({ meal_id: MEAL, entry_id: rice!.id });
        });

        it("MEAL-008 a recipe's swipe action deletes it", async () => {
            const view = show({ payload: content });

            await row(view, 'Overnight oats').get('[data-test="delete"]').trigger('click');
            await flushPromises();

            expect(sent('meal_food_remove')).toEqual({ meal_id: MEAL, entry_id: oats!.id });
        });

        it('MEAL-006 Rebalance re-fits the meal', async () => {
            const view = show({ payload: content });

            await view.get('[data-test="rebalance"]').trigger('click');
            await flushPromises();

            expect(sent('meal_rebalance')).toEqual({ meal_id: MEAL });
        });

        it("CAP-012 a refused write shows the server's words on the screen", async () => {
            writeMock.mockReset().mockRejectedValueOnce(new CapabilityError('meal_rebalance', 422, { ok: false, error: { message: 'Add a food first.' } }));
            const view = show({ payload: content });

            await view.get('[data-test="rebalance"]').trigger('click');
            await flushPromises();

            expect(view.get('[data-test="error"]').text()).toContain('Add a food first.');
        });
    });

    describe('while a write is in flight', () => {
        it('CAP-010 the rows, Rebalance, the tick and the menu are off, so a second tap sends nothing', async () => {
            writeMock.mockReset().mockReturnValue(new Promise(() => {}));
            const view = show({ payload: content });

            await row(view, 'White rice').get('[data-test="plus"]').trigger('click');
            await flushPromises();

            const rice = row(view, 'White rice');
            for (const control of ['minus', 'plus', 'lock', 'delete', 'amount']) expect(rice.get<HTMLButtonElement>(`[data-test="${control}"]`).element.disabled).toBe(true);
            expect(row(view, 'Overnight oats').get<HTMLButtonElement>('[data-test="plus"]').element.disabled).toBe(true);
            expect(view.get<HTMLButtonElement>('[data-test="rebalance"]').element.disabled).toBe(true);
            expect(view.get<HTMLButtonElement>('[data-test="check"]').element.disabled).toBe(true);
            expect(view.get<HTMLButtonElement>('button[aria-label="Meal menu"]').element.disabled).toBe(true);
            await rice.get('[data-test="minus"]').trigger('click');
            await view.get('[data-test="rebalance"]').trigger('click');
            expect(writeMock).toHaveBeenCalledTimes(1);
        });
    });

    describe('amount sheet', () => {
        it("MEAL-004 tapping a food's amount opens the sheet at the server's amount in grams", async () => {
            const view = show({ payload: content });

            await row(view, 'Chicken breast').get('[data-test="amount"]').trigger('click');

            expect(dialog()!.querySelector('h2')!.textContent).toBe('Chicken breast');
            expect(document.body.querySelector<HTMLInputElement>('[role="dialog"] input[name="amount"]')!.value).toBe('296');
            expect([...dialog()!.querySelectorAll('[role="radio"]')].map((el) => el.textContent!.trim())).toEqual(['g', 'fillet']);
        });

        it('MEAL-004 a typed amount in grams is sent, and the sheet closes', async () => {
            const view = show({ payload: content });
            await row(view, 'Chicken breast').get('[data-test="amount"]').trigger('click');

            await type('amount', '310,5');
            await saveSheet();

            expect(sent('meal_food_amount_set')).toEqual({ meal_id: MEAL, entry_id: chicken!.id, value: 310.5, unit: 'g' });
            expect(view.findComponent(MealAmountSheet).props('entry')).toBeNull();
        });

        it('MEAL-004 an amount in the household unit starts empty and is sent in that unit', async () => {
            const view = show({ payload: content });
            await row(view, 'Chicken breast').get('[data-test="amount"]').trigger('click');

            dialog()!.querySelectorAll<HTMLButtonElement>('[role="radio"]')[1]!.click();
            await flushPromises();
            expect(document.body.querySelector<HTMLInputElement>('[role="dialog"] input[name="amount"]')!.value).toBe('');
            await type('amount', '2');
            await saveSheet();

            expect(sent('meal_food_amount_set')).toEqual({ meal_id: MEAL, entry_id: chicken!.id, value: 2, unit: 'household' });
        });

        it('MEAL-004 a blank or negative amount cannot be saved', async () => {
            const view = show({ payload: content });
            await row(view, 'Chicken breast').get('[data-test="amount"]').trigger('click');
            const save = document.body.querySelector<HTMLButtonElement>('[role="dialog"] [data-save]')!;

            await type('amount', '');
            expect(save.disabled).toBe(true);
            await type('amount', '-5');
            expect(save.disabled).toBe(true);
            expect(writeMock).not.toHaveBeenCalled();
        });

        it("MEAL-004 a refused amount keeps the sheet open with the server's words", async () => {
            writeMock.mockReset().mockRejectedValueOnce(new CapabilityError('meal_food_amount_set', 422, { ok: false, error: { message: 'Too much.' } }));
            const view = show({ payload: content });
            await row(view, 'Chicken breast').get('[data-test="amount"]').trigger('click');

            await type('amount', '9000');
            await saveSheet();

            expect(dialog()!.textContent).toContain('Too much.');
            expect(view.find('[data-test="error"]').exists()).toBe(false);
        });
    });

    describe('menu', () => {
        it("MEAL-009 offers the server's items in its order, with its on and off", async () => {
            const view = show({ payload: content });

            await view.get('button[aria-label="Meal menu"]').trigger('click');

            const items = view.findAll('[role="menuitem"]');
            expect(items.map((item) => item.find('span').text())).toEqual(content.menu.map((entry) => entry.title));
            expect(view.get<HTMLButtonElement>('[data-test="item-remove_check_in"]').element.disabled).toBe(true);
            expect(view.get<HTMLButtonElement>('[data-test="item-copy_foods"]').element.disabled).toBe(false);
            expect(view.get('[data-test="item-delete_meal"]').classes()).toContain('text-tone-warning');
        });

        it('MEAL-009 Edit macros/time and manual check-in are off until their screens exist', async () => {
            const view = show({ payload: content });

            await view.get('button[aria-label="Meal menu"]').trigger('click');

            for (const key of ['edit_macros_time', 'check_in_manual']) {
                const item = view.get<HTMLButtonElement>(`[data-test="item-${key}"]`);
                expect(item.element.disabled).toBe(true);
                expect(item.text()).toContain('Not in the app yet');
            }
        });

        it('MEAL-009 actions that need foods are off on an empty meal, as the server says', async () => {
            const view = show({ payload: empty });

            await view.get('button[aria-label="Meal menu"]').trigger('click');

            for (const entry of empty.menu.filter((e) => !e.is_enabled)) expect(view.get<HTMLButtonElement>(`[data-test="item-${entry.item}"]`).element.disabled).toBe(true);
        });

        it('MEAL-009 Remove foods clears the meal', async () => {
            const view = show({ payload: content });

            await menu(view, 'remove_foods');

            expect(sent('meal_foods_clear')).toEqual({ meal_id: MEAL });
        });

        it('MEAL-040 Check in meal checks it in at its planned foods', async () => {
            const view = show({ payload: content });

            await menu(view, 'check_in_meal');

            expect(sent('meal_check_in')).toEqual({ meal_id: MEAL });
        });

        it('MEAL-044 Remove check-in removes it', async () => {
            const checked = { ...content, menu: content.menu.map((e) => (e.item === 'remove_check_in' ? { ...e, is_enabled: true } : e)) };
            const view = show({ payload: checked });

            await menu(view, 'remove_check_in');

            expect(sent('meal_check_in_undo')).toEqual({ meal_id: MEAL });
        });

        it('DAY-004 Still planning foods marks the meal as still being planned', async () => {
            const view = show({ payload: content });

            await menu(view, 'still_planning');

            expect(sent('meal_planning_done')).toEqual({ meal_id: MEAL, done: false });
        });

        it('DAY-004 Done planning foods marks the meal as planned', async () => {
            const planning = { ...short, menu: short.menu.map((e) => (e.item === 'still_planning' ? { ...e, item: 'done_planning' as const, title: 'Done planning foods' } : e)) };
            const view = show({ payload: planning });

            await menu(view, 'done_planning');

            expect(sent('meal_planning_done')).toEqual({ meal_id: short.meal_id, done: true });
        });

        it('MEAL-009 Delete meal asks first, then deletes it and goes back', async () => {
            const view = show({ payload: content });

            await menu(view, 'delete_meal');
            expect(dialog()!.querySelector('h2')!.textContent).toBe('Delete Dinner?');
            expect(dialog()!.textContent).toContain('Its foods and check-in go with it.');
            expect(writeMock).not.toHaveBeenCalled();

            dialog()!.querySelector<HTMLButtonElement>('[data-test="confirm-delete"]')!.click();
            await flushPromises();

            expect(sent('meal_delete')).toEqual({ meal_id: MEAL });
            expect(back).toHaveBeenCalled();
        });

        it('MEAL-009 a refused delete stays on the meal and says why', async () => {
            writeMock.mockReset().mockRejectedValueOnce(new CapabilityError('meal_delete', 422, { ok: false, error: { message: 'The meal is checked in.' } }));
            const view = show({ payload: content });

            await menu(view, 'delete_meal');
            dialog()!.querySelector<HTMLButtonElement>('[data-test="confirm-delete"]')!.click();
            await flushPromises();

            expect(back).not.toHaveBeenCalled();
            expect(view.get('[data-test="error"]').text()).toContain('The meal is checked in.');
        });

        it('MEAL-070 Load saved meal opens the picker for this meal, which reloads the meal', async () => {
            const view = show({ payload: content });
            expect(view.findComponent(SavedMealPickerSheet).props('open')).toBe(false);

            await menu(view, 'load_saved_meal');

            const picker = view.findComponent(SavedMealPickerSheet);
            expect(picker.props('open')).toBe(true);
            expect(picker.props('mealId')).toBe(MEAL);
            picker.vm.$emit('loaded');
            await flushPromises();
            expect(picker.props('open')).toBe(false);
        });

        it('MEAL-070 Save as saved meal starts at the meal name and saves its foods under the typed one', async () => {
            const view = show({ payload: content });

            await menu(view, 'save_as_saved_meal');
            expect(dialog()!.querySelector('h2')!.textContent).toBe('Save as saved meal');
            expect(document.body.querySelector<HTMLInputElement>('[role="dialog"] input[name="saved-meal-name"]')!.value).toBe('Dinner');
            await type('saved-meal-name', ' Post-gym dinner ');
            await saveSheet();

            const input = sent('saved_meal_save');
            expect(input).toEqual({ id: expect.stringMatching(/^[0-9A-HJKMNP-TV-Z]{26}$/), name: 'Post-gym dinner', meal_id: MEAL });
            expect(dialog()).toBeNull();
        });

        it('CAP-019 a saved meal retried after no answer keeps its id', async () => {
            writeMock.mockReset().mockRejectedValueOnce(new CapabilityError('saved_meal_save', 0, null)).mockResolvedValueOnce({});
            readMock.mockResolvedValue(content);
            const view = show({ payload: content });

            await menu(view, 'save_as_saved_meal');
            await saveSheet();
            await saveSheet();

            expect(writeMock).toHaveBeenCalledTimes(2);
            expect((writeMock.mock.calls[1]![1] as { id: string }).id).toBe((writeMock.mock.calls[0]![1] as { id: string }).id);
        });

        it('CAP-019 a save under another name after no answer is a new saved meal, with a new id', async () => {
            writeMock.mockReset().mockRejectedValueOnce(new CapabilityError('saved_meal_save', 0, null)).mockResolvedValueOnce({});
            const view = show({ payload: content });

            await menu(view, 'save_as_saved_meal');
            await saveSheet();
            await type('saved-meal-name', 'Lean dinner');
            await saveSheet();

            const [first, second] = writeMock.mock.calls.map((call) => call[1] as { id: string; name: string });
            expect(second!.name).toBe('Lean dinner');
            expect(second!.id).not.toBe(first!.id);
        });

        it('CAP-019 a save closed after no answer and started again under another name gets a new id', async () => {
            writeMock.mockReset().mockRejectedValueOnce(new CapabilityError('saved_meal_save', 0, null)).mockResolvedValueOnce({});
            const view = show({ payload: content });

            await menu(view, 'save_as_saved_meal');
            await saveSheet();
            document.body.querySelector<HTMLButtonElement>('[role="dialog"] [data-cancel]')!.click();
            await flushPromises();
            await menu(view, 'save_as_saved_meal');
            await type('saved-meal-name', 'Lean dinner');
            await saveSheet();

            const [first, second] = writeMock.mock.calls.map((call) => call[1] as { id: string });
            expect(second!.id).not.toBe(first!.id);
        });

        it("MEAL-070 a refused saved meal keeps the sheet open with the server's words, and the next save is a new meal", async () => {
            writeMock.mockReset().mockRejectedValueOnce(new CapabilityError('saved_meal_save', 422, { ok: false, error: { message: 'That name is taken.' } })).mockResolvedValueOnce({});
            const view = show({ payload: content });

            await menu(view, 'save_as_saved_meal');
            await saveSheet();
            expect(dialog()!.textContent).toContain('That name is taken.');
            expect(view.find('[data-test="error"]').exists()).toBe(false);
            await saveSheet();

            const [first, second] = writeMock.mock.calls.map((call) => call[1] as { id: string });
            expect(second!.id).not.toBe(first!.id);
            expect(dialog()).toBeNull();
        });

        it('MEAL-060 Copy foods opens the copy sheet for this meal', async () => {
            const view = show({ payload: content });

            await menu(view, 'copy_foods');

            const copy = view.findComponent(CopySheet);
            expect(copy.props('mealId')).toBe(MEAL);
            copy.vm.$emit('close');
            await flushPromises();
            expect(view.findComponent(CopySheet).exists()).toBe(false);
        });
    });

    describe('check-in tick', () => {
        it('MEAL-040 the tick inside the meal checks it in', async () => {
            const view = show({ payload: content });

            const tick = view.get('[data-test="check"]');
            expect(tick.attributes('aria-label')).toBe('Check in Dinner');
            await tick.trigger('click');
            await flushPromises();

            expect(sent('meal_check_in')).toEqual({ meal_id: MEAL });
        });

        it("MEAL-044 MEAL-047 on a checked-in meal the tick shows the server's state and removes the check-in", async () => {
            const checked: MealShowOutput = {
                ...content,
                check_in: { state: 'under', text: 'Checked in under target', tone: 'warning' },
                menu: content.menu.map((e) => ({ ...e, is_enabled: e.item === 'remove_check_in' ? true : e.item === 'check_in_meal' ? false : e.is_enabled })),
            };
            const view = show({ payload: checked });

            expect(view.get('[data-test="check-in-text"]').text()).toBe('Checked in under target');
            const tick = view.get('[data-test="check"]');
            expect(tick.attributes('aria-label')).toBe('Remove check-in for Dinner');
            await tick.trigger('click');
            await flushPromises();

            expect(sent('meal_check_in_undo')).toEqual({ meal_id: MEAL });
        });

        it('MEAL-045 the tick is off when the menu offers no check-in, as on a badged meal', () => {
            const badged = { ...short, menu: short.menu.map((e) => (e.item === 'check_in_meal' ? { ...e, is_enabled: false } : e)) };

            expect(show({ payload: badged }).get<HTMLButtonElement>('[data-test="check"]').element.disabled).toBe(true);
        });
    });
});
