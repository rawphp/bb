import { enableAutoUnmount, flushPromises } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { CapabilityError, read, readFresh, write } from '@/app/call';
import type { DayAdjustShowOutput } from '@/contracts/day_adjust_show';
import { inputViolations, screenFixture } from '@/testing/schemas';
import AdjustSheet from './AdjustSheet.vue';
import { mountSheet } from './sheetTesting';

// A sheet left mounted would still hear the next test's online event.
enableAutoUnmount(afterEach);

vi.mock('@/app/call', async (original) => ({ ...(await original<typeof import('@/app/call')>()), read: vi.fn(), readFresh: vi.fn(), write: vi.fn() }));

const readMock = vi.mocked(read);
const freshMock = vi.mocked(readFresh);
const writeMock = vi.mocked(write);
const content = screenFixture<DayAdjustShowOutput>('day_adjust_show');
const refused = screenFixture<DayAdjustShowOutput>('day_adjust_show.warning');
const meals: DayAdjustShowOutput = { ...content, mode: 'meals', other_day: null };

function lastRead(): Record<string, unknown> {
    const [name, input] = readMock.mock.calls.at(-1)!;
    expect(name).toBe('day_adjust_show');
    expect(inputViolations('day_adjust_show', input)).toEqual([]);
    return input as Record<string, unknown>;
}

// A changed move is asked of the server, never the cache: an old copy of the same pick predates any move saved since.
function lastAsked(): Record<string, unknown> {
    const [name, input] = freshMock.mock.calls.at(-1)!;
    expect(name).toBe('day_adjust_show');
    expect(inputViolations('day_adjust_show', input)).toEqual([]);
    return input as Record<string, unknown>;
}

// The calorie field asks the server once typing stops (DEBOUNCE_MS), so a typed amount runs the clock past it.
// A segment of one of the sheet's segmented pickers, found by the picker's label and its own text.
function segment(wrapper: Awaited<ReturnType<typeof mountSheet>>['wrapper'], picker: string, text: string) {
    const found = wrapper.findAll(`[role="radiogroup"][aria-label="${picker}"] [role="radio"]`).find((radio) => radio.text() === text);
    expect(found, `${picker}: ${text}`).toBeDefined();
    return found!;
}

async function typeKcal(wrapper: Awaited<ReturnType<typeof mountSheet>>['wrapper'], text: string) {
    await wrapper.get('input[name="kcal"]').setValue(text);
    vi.advanceTimersByTime(300);
    await flushPromises();
}

describe('AdjustSheet', () => {
    beforeEach(() => {
        readMock.mockReset();
        freshMock.mockReset();
        writeMock.mockReset();
        readMock.mockResolvedValue(content);
        freshMock.mockResolvedValue(content);
        vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] });
    });

    afterEach(() => vi.useRealTimers());

    it('DAY-020 shows both days\' projected calories and protein', async () => {
        const { wrapper } = await mountSheet(AdjustSheet, { payload: content });

        expect(wrapper.text()).toContain('Adjust day');
        expect(wrapper.text()).toContain('Projected');
        const lines = wrapper.findAll('[data-test="projected"]').map((line) => line.text());
        expect(lines).toEqual([
            expect.stringContaining('Wed, Sep 23'),
            expect.stringContaining('Sat, Sep 26'),
        ]);
        expect(lines[0]).toContain('2,400 kcal');
        expect(lines[0]).toContain('170 g protein');
        expect(readMock).not.toHaveBeenCalled();
    });

    it('DAY-021 says small misses are fine', async () => {
        const { wrapper } = await mountSheet(AdjustSheet, { payload: content });

        expect(wrapper.text()).toContain('Small misses are fine. Within 10% on calories and protein each day is a success.');
    });

    it('DAY-020 between days offers the other days of the week, a finished one off', async () => {
        const { wrapper } = await mountSheet(AdjustSheet, { payload: content });

        expect(segment(wrapper, 'Move calories', 'Between days').attributes('aria-checked')).toBe('true');
        expect(segment(wrapper, 'Direction', 'Give to').attributes('aria-checked')).toBe('true');
        const options = wrapper.findAll<HTMLOptionElement>('select[name="day"] option[value]:not([value=""])');
        expect(options.map((o) => o.text())).toEqual(['Tue, Sep 22 (finished)', 'Sat, Sep 26']);
        expect(options.map((o) => o.element.disabled)).toEqual([true, false]);
        // Between days lists no meals.
        expect(wrapper.find('select[name="from"]').exists()).toBe(false);
    });

    it('DAY-020 between meals offers the open meals as From and To and shows the meals as the move leaves them', async () => {
        const { wrapper } = await mountSheet(AdjustSheet, { payload: meals });

        for (const select of ['from', 'to']) {
            const names = wrapper.findAll(`select[name="${select}"] option[value]:not([value=""])`).map((o) => o.text());
            expect(names).toEqual(['Lunch', 'Pre-workout', 'Post-workout']);
        }
        expect(wrapper.find('select[name="day"]').exists()).toBe(false);
        const lines = wrapper.findAll('[data-test="projected-meal"]').map((line) => line.text());
        expect(lines).toEqual([expect.stringContaining('Lunch')]);
        expect(lines[0]).toContain('553 kcal');
        expect(lines[0]).toContain('42 g protein');
    });

    it('DAY-020 between meals says so when the day has fewer than two open meals', async () => {
        const { wrapper } = await mountSheet(AdjustSheet, { payload: { ...meals, meal_options: meals.meal_options.slice(0, 1) } });

        expect(wrapper.text()).toContain('Calories move between meals that are not checked in. This day has fewer than two.');
        expect(wrapper.find('select[name="from"]').exists()).toBe(false);
    });

    it('DAY-020 shows why the server refuses a move, with its warnings, and keeps Save off', async () => {
        const { wrapper } = await mountSheet(AdjustSheet, { payload: refused });

        expect(wrapper.text()).toContain(refused.refusal);
        expect(wrapper.text()).toContain('Carbs drop below 50 g on Wed, Sep 23.');
        expect(wrapper.get('[data-save]').attributes('disabled')).toBeDefined();
    });

    it('DAY-020 asks the server again, not the cache, as the user fills the sheet in', async () => {
        const { wrapper } = await mountSheet(AdjustSheet, { date: '2026-09-23' });
        await flushPromises();
        expect(lastRead()).toEqual({ date: '2026-09-23' });
        expect(freshMock).not.toHaveBeenCalled();

        await wrapper.get('select[name="day"]').setValue('2026-09-26');
        await segment(wrapper, 'Direction', 'Take from').trigger('click');
        await typeKcal(wrapper, '200');
        expect(lastAsked()).toEqual({ date: '2026-09-23', mode: 'days', direction: 'take', other_date: '2026-09-26', kcal: 200 });

        await segment(wrapper, 'Move calories', 'Between meals').trigger('click');
        await flushPromises();
        await wrapper.get('select[name="from"]').setValue(content.meal_options[0]!.id);
        await wrapper.get('select[name="to"]').setValue(content.meal_options[2]!.id);
        await flushPromises();
        expect(lastAsked()).toEqual({
            date: '2026-09-23',
            mode: 'meals',
            from_meal_id: content.meal_options[0]!.id,
            to_meal_id: content.meal_options[2]!.id,
            kcal: 200,
        });
    });

    it('DAY-020 sends a calorie amount that is not a whole number as none, so the server asks for one', async () => {
        const { wrapper } = await mountSheet(AdjustSheet, { date: '2026-09-23' });
        await flushPromises();

        await typeKcal(wrapper, '2.5');

        expect(lastAsked()).toMatchObject({ kcal: null });
    });

    it('DAY-020 saves the move as filled in and hands the server\'s toast to the screen', async () => {
        const result = { invalidates: [], toast: 'Moved 200 kcal', undo: null };
        writeMock.mockResolvedValueOnce(result);
        const onSaved = vi.fn();
        const { wrapper } = await mountSheet(AdjustSheet, { payload: content, onSaved });

        await wrapper.get('select[name="day"]').setValue('2026-09-26');
        await typeKcal(wrapper, '200');
        await wrapper.get('[data-save]').trigger('click');
        await flushPromises();

        const [name, input] = writeMock.mock.calls.at(-1)!;
        expect(name).toBe('day_adjust');
        expect(inputViolations('day_adjust', input)).toEqual([]);
        expect(input).toEqual({ date: '2026-09-23', mode: 'days', direction: 'give', other_date: '2026-09-26', kcal: 200 });
        expect(onSaved).toHaveBeenCalledWith(result);
    });

    it('DAY-020 saves a move between meals as picked', async () => {
        writeMock.mockResolvedValueOnce({ invalidates: [], toast: 'Moved 150 kcal', undo: null });
        // The server answers in the mode it was asked about.
        freshMock.mockResolvedValue(meals);
        const { wrapper } = await mountSheet(AdjustSheet, { payload: meals, onSaved: vi.fn() });
        const [lunch, , postWorkout] = meals.meal_options;

        await wrapper.get('select[name="from"]').setValue(postWorkout!.id);
        await wrapper.get('select[name="to"]').setValue(lunch!.id);
        await typeKcal(wrapper, '150');
        await wrapper.get('[data-save]').trigger('click');
        await flushPromises();

        const [name, input] = writeMock.mock.calls.at(-1)!;
        expect(name).toBe('day_adjust');
        expect(inputViolations('day_adjust', input)).toEqual([]);
        expect(input).toEqual({ date: '2026-09-23', mode: 'meals', from_meal_id: postWorkout!.id, to_meal_id: lunch!.id, kcal: 150 });
    });

    it('DAY-020 saves a move that takes calories from another day', async () => {
        writeMock.mockResolvedValueOnce({ invalidates: [], toast: 'Moved 300 kcal', undo: null });
        const { wrapper } = await mountSheet(AdjustSheet, { payload: content, onSaved: vi.fn() });

        await segment(wrapper, 'Direction', 'Take from').trigger('click');
        await wrapper.get('select[name="day"]').setValue('2026-09-26');
        await typeKcal(wrapper, '300');
        await wrapper.get('[data-save]').trigger('click');
        await flushPromises();

        const [name, input] = writeMock.mock.calls.at(-1)!;
        expect(name).toBe('day_adjust');
        expect(inputViolations('day_adjust', input)).toEqual([]);
        expect(input).toEqual({ date: '2026-09-23', mode: 'days', direction: 'take', other_date: '2026-09-26', kcal: 300 });
    });

    it('DAY-020 asks the server once typing stops, not on every key, and keeps Save off meanwhile', async () => {
        const { wrapper } = await mountSheet(AdjustSheet, { payload: content });
        await wrapper.get('select[name="day"]').setValue('2026-09-26');
        await flushPromises();
        freshMock.mockClear();

        for (const text of ['2', '20', '200']) {
            await wrapper.get('input[name="kcal"]').setValue(text);
            vi.advanceTimersByTime(100);
            await flushPromises();
        }
        expect(freshMock).not.toHaveBeenCalled();
        expect(wrapper.get('[data-save]').attributes('disabled')).toBeDefined();

        vi.advanceTimersByTime(300);
        await flushPromises();
        expect(freshMock).toHaveBeenCalledOnce();
        expect(lastAsked()).toMatchObject({ kcal: 200 });
        expect(wrapper.get('[data-save]').attributes('disabled')).toBeUndefined();
    });

    it('DAY-020 asks the server at once for a pick, with whatever amount is typed', async () => {
        const { wrapper } = await mountSheet(AdjustSheet, { payload: content });

        await wrapper.get('input[name="kcal"]').setValue('250');
        await wrapper.get('select[name="day"]').setValue('2026-09-26');
        await flushPromises();
        expect(freshMock).toHaveBeenCalledOnce();
        expect(lastAsked()).toMatchObject({ other_date: '2026-09-26', kcal: 250 });

        // The pick already asked for the typed amount, so the pending one is dropped.
        vi.advanceTimersByTime(300);
        await flushPromises();
        expect(freshMock).toHaveBeenCalledOnce();
    });

    it('CAP-012 shows the server\'s words when it refuses the save, and stays open', async () => {
        writeMock.mockRejectedValueOnce(new CapabilityError('day_adjust', 422, { ok: false, error: { message: 'Sat, Sep 26 is finished.' } }));
        const onSaved = vi.fn();
        const { wrapper } = await mountSheet(AdjustSheet, { payload: content, onSaved });

        await typeKcal(wrapper, '200');
        await wrapper.get('[data-save]').trigger('click');
        await flushPromises();

        expect(wrapper.get('[role="alert"]').text()).toBe('Sat, Sep 26 is finished.');
        expect(onSaved).not.toHaveBeenCalled();
    });

    it('DAY-020 keeps Save off until an amount is typed', async () => {
        const { wrapper } = await mountSheet(AdjustSheet, { payload: content });

        expect(wrapper.get('[data-save]').attributes('disabled')).toBeDefined();
        await typeKcal(wrapper, '200');
        expect(wrapper.get('[data-save]').attributes('disabled')).toBeUndefined();
    });

    it('DAY-020 keeps Save off while the answer for the latest change is on its way', async () => {
        const { wrapper } = await mountSheet(AdjustSheet, { payload: content });
        let answer: (data: DayAdjustShowOutput) => void = () => {};
        freshMock.mockReturnValueOnce(new Promise((resolve) => (answer = resolve as typeof answer)));

        await typeKcal(wrapper, '200');
        expect(wrapper.get('[data-save]').attributes('disabled')).toBeDefined();

        answer(content);
        await flushPromises();
        expect(wrapper.get('[data-save]').attributes('disabled')).toBeUndefined();
    });

    it('OFF-003 keeps the sheet and what was typed when a changed move cannot be read, says why, and keeps Save off', async () => {
        freshMock.mockRejectedValueOnce(new CapabilityError('day_adjust_show', 0, { reason: 'network' }));
        const { wrapper } = await mountSheet(AdjustSheet, { payload: content });

        await typeKcal(wrapper, '200');

        expect((wrapper.get('input[name="kcal"]').element as HTMLInputElement).value).toBe('200');
        expect(wrapper.findAll('[data-test="projected"]')).toHaveLength(2);
        expect(wrapper.get('[role="alert"]').text()).toBe('No connection. Try again when you are online.');
        expect(wrapper.get('[data-save]').attributes('disabled')).toBeDefined();
    });

    it('DAY-020 an older answer neither turns Save on nor leaves its error behind', async () => {
        const { wrapper } = await mountSheet(AdjustSheet, { payload: content });
        const answers: { resolve: (data: DayAdjustShowOutput) => void; reject: (error: unknown) => void }[] = [];
        freshMock.mockImplementation(() => new Promise((resolve, reject) => answers.push({ resolve: resolve as never, reject })));

        await typeKcal(wrapper, '2');
        await typeKcal(wrapper, '20');
        answers[0]!.resolve(content);
        await flushPromises();
        expect(wrapper.get('[data-save]').attributes('disabled')).toBeDefined();
        answers[1]!.resolve(content);
        await flushPromises();
        expect(wrapper.get('[data-save]').attributes('disabled')).toBeUndefined();

        await typeKcal(wrapper, '200');
        await typeKcal(wrapper, '2000');
        answers[3]!.resolve(content);
        await flushPromises();
        answers[2]!.reject(new CapabilityError('day_adjust_show', 0, { reason: 'network' }));
        await flushPromises();
        expect(wrapper.find('[role="alert"]').exists()).toBe(false);
        expect(wrapper.get('[data-save]').attributes('disabled')).toBeUndefined();
    });

    it('OFF-003 a read that ends the session takes the numbers away and says so', async () => {
        freshMock.mockRejectedValueOnce(new CapabilityError('day_adjust_show', 401, null));
        const { wrapper } = await mountSheet(AdjustSheet, { payload: content });

        await typeKcal(wrapper, '200');

        expect(wrapper.find('[data-test="projected"]').exists()).toBe(false);
        expect(wrapper.text()).toContain('You are signed out.');
    });

    it('OFF-004 asks again when the device comes back online', async () => {
        freshMock.mockRejectedValueOnce(new CapabilityError('day_adjust_show', 0, { reason: 'network' }));
        const { wrapper } = await mountSheet(AdjustSheet, { payload: content });
        await typeKcal(wrapper, '200');

        window.dispatchEvent(new Event('online'));
        await flushPromises();

        expect(freshMock).toHaveBeenCalledTimes(2);
        expect(lastAsked()).toMatchObject({ kcal: 200 });
        expect(wrapper.find('[role="alert"]').exists()).toBe(false);
    });

    it('CAP-014 a retry after no answer sends the same move with the same key, and the move cannot change meanwhile', async () => {
        writeMock.mockRejectedValueOnce(new CapabilityError('day_adjust', 0, { reason: 'timeout' })).mockResolvedValueOnce({ invalidates: [] });
        const { wrapper } = await mountSheet(AdjustSheet, { payload: content, onSaved: vi.fn() });
        await wrapper.get('select[name="day"]').setValue('2026-09-26');
        await typeKcal(wrapper, '200');

        await wrapper.get('[data-save]').trigger('click');
        await flushPromises();
        expect(wrapper.get('input[name="kcal"]').element.matches(':disabled')).toBe(true);
        expect(segment(wrapper, 'Move calories', 'Between meals').element.matches(':disabled')).toBe(true);
        await wrapper.get('[data-save]').trigger('click');
        await flushPromises();

        const [first, second] = writeMock.mock.calls;
        expect(second![1]).toEqual(first![1]);
        expect(second![2]?.idempotencyKey).toBe(first![2]?.idempotencyKey);
    });

    it('UI-005 Cancel closes the sheet without saving', async () => {
        const onClose = vi.fn();
        const { wrapper } = await mountSheet(AdjustSheet, { payload: content, onClose });

        await wrapper.get('[data-cancel]').trigger('click');

        expect(onClose).toHaveBeenCalledOnce();
        expect(writeMock).not.toHaveBeenCalled();
    });

    it('OFF-003 shows a skeleton while the sheet loads', async () => {
        readMock.mockReturnValueOnce(new Promise(() => {}));
        const { wrapper } = await mountSheet(AdjustSheet, { date: '2026-09-23' });
        await flushPromises();

        expect(wrapper.find('[data-test="skeleton"]').exists()).toBe(true);
        expect(wrapper.get('[data-save]').attributes('disabled')).toBeDefined();
    });

    it('OFF-003 says the sheet could not load, with Try again', async () => {
        readMock.mockRejectedValueOnce(new CapabilityError('day_adjust_show', 500, null));
        const { wrapper } = await mountSheet(AdjustSheet, { date: '2026-09-23' });
        await flushPromises();

        expect(wrapper.text()).toContain('The Adjust sheet could not be loaded.');
        await wrapper.get('[data-test="retry"]').trigger('click');
        await flushPromises();
        expect(wrapper.findAll('[data-test="projected"]')).toHaveLength(2);
    });

    it('OFF-008 dims the projected numbers while a change waits to sync', async () => {
        const { wrapper } = await mountSheet(AdjustSheet, { payload: meals, pending: true });

        expect(wrapper.findAll('[data-mark]').length).toBeGreaterThan(0);
        const values = wrapper.findAll('[data-value]');
        expect(values.map((v) => v.text())).toEqual(expect.arrayContaining([expect.stringContaining('2,400 kcal'), expect.stringContaining('553 kcal')]));
        expect(values.every((v) => v.classes('opacity-60'))).toBe(true);
    });

    it('OFF-008 shows no mark when nothing waits to sync', async () => {
        const { wrapper } = await mountSheet(AdjustSheet, { payload: meals });

        expect(wrapper.find('[data-mark]').exists()).toBe(false);
    });
});
