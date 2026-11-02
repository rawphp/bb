import { flushPromises } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { CapabilityError, read, write } from '@/app/call';
import type { DayRedistributeShowOutput } from '@/contracts/day_redistribute_show';
import { inputViolations, screenFixture } from '@/testing/schemas';
import RedistributeSheet from './RedistributeSheet.vue';
import { mountSheet } from './sheetTesting';

vi.mock('@/app/call', async (original) => ({ ...(await original<typeof import('@/app/call')>()), read: vi.fn(), write: vi.fn() }));

const readMock = vi.mocked(read);
const writeMock = vi.mocked(write);
const content = screenFixture<DayRedistributeShowOutput>('day_redistribute_show');
const warned = screenFixture<DayRedistributeShowOutput>('day_redistribute_show.warning');
const [lunch, preWorkout, postWorkout] = content.rows;

function lastRead(): Record<string, unknown> {
    const [name, input] = readMock.mock.calls.at(-1)!;
    expect(name).toBe('day_redistribute_show');
    expect(inputViolations('day_redistribute_show', input)).toEqual([]);
    return input as Record<string, unknown>;
}

describe('RedistributeSheet', () => {
    beforeEach(() => {
        readMock.mockReset();
        writeMock.mockReset();
        readMock.mockResolvedValue(content);
    });

    it('DAY-002 lists the later meals with what each would hold, and which take the rest', async () => {
        const { wrapper } = await mountSheet(RedistributeSheet, { payload: content });

        expect(wrapper.text()).toContain('Redistribute');
        expect(wrapper.text()).toContain("Choose the meals that take the rest of the day's calories and macros.");
        const rows = wrapper.findAll('[data-test="redistribute-row"]');
        expect(rows.map((row) => row.text())).toEqual([
            expect.stringContaining('Lunch'),
            expect.stringContaining('Pre-workout'),
            expect.stringContaining('Post-workout'),
        ]);
        expect(rows[0]!.text()).toContain('12:30 PM');
        expect(rows[0]!.text()).toContain('880 kcal');
        expect(rows[0]!.text()).toContain('58 g protein');
        expect(rows.map((row) => row.attributes('aria-pressed'))).toEqual(['true', 'false', 'true']);
        expect(wrapper.get('[data-save]').text()).toBe('Confirm');
        expect(readMock).not.toHaveBeenCalled();
    });

    it('DAY-021 says small misses are fine', async () => {
        const { wrapper } = await mountSheet(RedistributeSheet, { payload: content });

        expect(wrapper.text()).toContain(content.note);
    });

    it('DAY-002 shows the server\'s warnings in the warning tone', async () => {
        const { wrapper } = await mountSheet(RedistributeSheet, { payload: warned });

        const warning = wrapper.get('[data-test="warning"]');
        expect(warning.text()).toBe('Post-workout would hold over 120 g of carbs.');
        expect(warning.classes()).toContain('text-tone-warning');
    });

    it('DAY-002 leaves a tapped meal out, takes it back on a second tap, and asks the server each time', async () => {
        const { wrapper } = await mountSheet(RedistributeSheet, { date: '2026-09-23' });
        await flushPromises();
        expect(lastRead()).toEqual({ date: '2026-09-23' });

        readMock.mockResolvedValueOnce({ ...content, rows: [{ ...lunch!, selected: false }, preWorkout!, postWorkout!] });
        await wrapper.findAll('[data-test="redistribute-row"]')[0]!.trigger('click');
        await flushPromises();
        expect(lastRead()).toEqual({ date: '2026-09-23', excluded_meal_ids: [lunch!.id] });

        await wrapper.findAll('[data-test="redistribute-row"]')[0]!.trigger('click');
        await flushPromises();
        expect(lastRead()).toEqual({ date: '2026-09-23', excluded_meal_ids: [] });
    });

    it('DAY-002 Confirm sends the meals the server shows as taking the rest, and hands its toast to the screen', async () => {
        const result = { invalidates: [], toast: 'Redistributed over 2 meals', undo: '01JBX0U1V2W3X4Y5Z6A7B8C9D0' };
        writeMock.mockResolvedValueOnce(result);
        const onSaved = vi.fn();
        const { wrapper } = await mountSheet(RedistributeSheet, { payload: content, onSaved });

        await wrapper.get('[data-save]').trigger('click');
        await flushPromises();

        const [name, input] = writeMock.mock.calls.at(-1)!;
        expect(name).toBe('meal_redistribute');
        expect(inputViolations('meal_redistribute', input)).toEqual([]);
        expect(input).toEqual({ date: '2026-09-23', meal_ids: [lunch!.id, postWorkout!.id] });
        expect(onSaved).toHaveBeenCalledWith(result);
    });

    it('DAY-002 keeps Confirm off while the server says no meal can take the rest', async () => {
        const { wrapper } = await mountSheet(RedistributeSheet, { payload: { ...content, can_confirm: false } });

        expect(wrapper.get('[data-save]').attributes('disabled')).toBeDefined();
    });

    it('CAP-012 shows the server\'s words when it refuses, and stays open', async () => {
        writeMock.mockRejectedValueOnce(new CapabilityError('meal_redistribute', 422, { ok: false, error: { message: 'Lunch is checked in.' } }));
        const onSaved = vi.fn();
        const { wrapper } = await mountSheet(RedistributeSheet, { payload: content, onSaved });

        await wrapper.get('[data-save]').trigger('click');
        await flushPromises();

        expect(wrapper.get('[role="alert"]').text()).toBe('Lunch is checked in.');
        expect(onSaved).not.toHaveBeenCalled();
    });

    it('UI-005 Cancel closes the sheet without saving', async () => {
        const onClose = vi.fn();
        const { wrapper } = await mountSheet(RedistributeSheet, { payload: content, onClose });

        await wrapper.get('[data-cancel]').trigger('click');

        expect(onClose).toHaveBeenCalledOnce();
        expect(writeMock).not.toHaveBeenCalled();
    });

    it('OFF-003 shows a skeleton while the sheet loads', async () => {
        readMock.mockReturnValueOnce(new Promise(() => {}));
        const { wrapper } = await mountSheet(RedistributeSheet, { date: '2026-09-23' });
        await flushPromises();

        expect(wrapper.find('[data-test="skeleton"]').exists()).toBe(true);
        expect(wrapper.get('[data-save]').attributes('disabled')).toBeDefined();
    });

    it('OFF-003 says the sheet could not load, with Try again', async () => {
        readMock.mockRejectedValueOnce(new CapabilityError('day_redistribute_show', 500, null));
        const { wrapper } = await mountSheet(RedistributeSheet, { date: '2026-09-23' });
        await flushPromises();

        expect(wrapper.text()).toContain('The meals to redistribute over could not be loaded.');
        await wrapper.get('[data-test="retry"]').trigger('click');
        await flushPromises();
        expect(wrapper.findAll('[data-test="redistribute-row"]')).toHaveLength(3);
    });

    it('OFF-008 dims each meal\'s projected numbers while a change waits to sync', async () => {
        const { wrapper } = await mountSheet(RedistributeSheet, { payload: content, pending: true });

        expect(wrapper.findAll('[data-mark]')).toHaveLength(content.rows.length);
        const values = wrapper.findAll('[data-value]');
        expect(values.map((v) => v.text())).toEqual(expect.arrayContaining([expect.stringContaining('880 kcal')]));
        expect(values.every((v) => v.classes('opacity-60'))).toBe(true);
    });
});
