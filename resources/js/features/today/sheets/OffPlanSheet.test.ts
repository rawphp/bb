import { enableAutoUnmount, flushPromises } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { CapabilityError, read, write } from '@/app/call';
import { inputViolations } from '@/testing/schemas';
import OffPlanSheet from './OffPlanSheet.vue';
import { mountSheet } from './sheetTesting';

// A sheet left mounted would still hear the next test's online event.
enableAutoUnmount(afterEach);

vi.mock('@/app/call', async (original) => ({ ...(await original<typeof import('@/app/call')>()), read: vi.fn(), write: vi.fn() }));

const readMock = vi.mocked(read);
const writeMock = vi.mocked(write);

function sent(): Record<string, unknown> {
    const [name, input] = writeMock.mock.calls.at(-1)!;
    expect(name).toBe('off_plan_log');
    expect(inputViolations('off_plan_log', input)).toEqual([]);
    return input as Record<string, unknown>;
}

async function fill(wrapper: Awaited<ReturnType<typeof mountSheet>>['wrapper'], values: Record<string, string>) {
    for (const [field, value] of Object.entries(values)) await wrapper.get(`input[name="${field}"]`).setValue(value);
}

describe('OffPlanSheet', () => {
    beforeEach(() => {
        readMock.mockReset();
        writeMock.mockReset();
    });

    afterEach(() => vi.restoreAllMocks());

    it('DAY-001 logs food eaten off-plan as an entry of the day, with its name, time, calories and macros', async () => {
        const result = { invalidates: [], toast: 'Saved Office cake', undo: null };
        writeMock.mockResolvedValueOnce(result);
        const onSaved = vi.fn();
        const { wrapper } = await mountSheet(OffPlanSheet, { date: '2026-09-23', time: '12:00', onSaved });

        expect(wrapper.text()).toContain('Save food macros');
        await fill(wrapper, { name: 'Office cake', time: '15:00', kcal: '320', protein: '4', fat: '16.5', carbs: '40' });
        await wrapper.get('[data-save]').trigger('click');
        await flushPromises();

        const input = sent();
        expect(input).toEqual({ id: expect.stringMatching(/^[0-9A-HJKMNP-TV-Z]{26}$/), date: '2026-09-23', time: '15:00', name: 'Office cake', kcal: 320, protein_g: 4, fat_g: 16.5, carbs_g: 40 });
        expect(onSaved).toHaveBeenCalledWith(result);
        expect(readMock).not.toHaveBeenCalled();
    });

    it('DAY-001 sends no name when none is typed, and an empty amount as 0', async () => {
        writeMock.mockResolvedValueOnce({ invalidates: [] });
        const { wrapper } = await mountSheet(OffPlanSheet, { date: '2026-09-23', time: '18:30' });

        await fill(wrapper, { kcal: '250' });
        await wrapper.get('[data-save]').trigger('click');
        await flushPromises();

        expect(sent()).toMatchObject({ name: null, time: '18:30', kcal: 250, protein_g: 0, fat_g: 0, carbs_g: 0 });
    });

    it('DAY-001 opens on the clock\'s day and time when the screen gives none', async () => {
        vi.spyOn(Date, 'now').mockReturnValue(new Date(2026, 8, 23, 7, 5).getTime());
        writeMock.mockResolvedValueOnce({ invalidates: [] });
        const { wrapper } = await mountSheet(OffPlanSheet);

        expect((wrapper.get('input[name="time"]').element as HTMLInputElement).value).toBe('07:05');
        await wrapper.get('[data-save]').trigger('click');
        await flushPromises();

        expect(sent()).toMatchObject({ date: '2026-09-23', time: '07:05' });
    });

    it.each(['-5', 'abc', '1e'])('DAY-001 keeps Save off while an amount reads %s', async (typed) => {
        const { wrapper } = await mountSheet(OffPlanSheet, { date: '2026-09-23', time: '12:00' });

        await fill(wrapper, { protein: typed });

        expect(wrapper.get('[data-save]').attributes('disabled')).toBeDefined();
    });

    it('UI-011 colours each amount by its macro and names its unit for screen readers', async () => {
        const { wrapper } = await mountSheet(OffPlanSheet, { date: '2026-09-23', time: '12:00' });

        const rows = wrapper.findAll('[data-test="amount-row"]');
        expect(rows.map((row) => row.get('[data-test="amount-title"]').classes())).toEqual([
            expect.arrayContaining(['text-macro-calories']),
            expect.arrayContaining(['text-macro-protein']),
            expect.arrayContaining(['text-macro-fat']),
            expect.arrayContaining(['text-macro-carbs']),
        ]);
        expect(wrapper.get('input[name="kcal"]').attributes('aria-label')).toBe('Calories in kilocalories');
        expect(wrapper.get('input[name="protein"]').attributes('aria-label')).toBe('Protein in grams');
    });

    it.each([
        ['12,5', 12.5],
        ['.5', 0.5],
        ['7.', 7],
    ])('DAY-001 reads %s as %s grams, a comma as the decimal point', async (typed, grams) => {
        writeMock.mockResolvedValueOnce({ invalidates: [] });
        const { wrapper } = await mountSheet(OffPlanSheet, { date: '2026-09-23', time: '12:00' });

        await fill(wrapper, { protein: typed });
        await wrapper.get('[data-save]').trigger('click');
        await flushPromises();

        expect(sent()).toMatchObject({ protein_g: grams });
    });

    it('DAY-001 keeps Save off while the time is empty', async () => {
        const { wrapper } = await mountSheet(OffPlanSheet, { date: '2026-09-23', time: '12:00' });

        await fill(wrapper, { time: '' });

        expect(wrapper.get('[data-save]').attributes('disabled')).toBeDefined();
    });

    it('CAP-019 each opening is a new entry: the screen mounts the sheet afresh', async () => {
        writeMock.mockResolvedValue({ invalidates: [] });
        const first = await mountSheet(OffPlanSheet, { date: '2026-09-23', time: '12:00', onSaved: vi.fn() });
        await fill(first.wrapper, { kcal: '320' });
        await first.wrapper.get('[data-save]').trigger('click');
        await flushPromises();
        const firstId = sent().id;

        const second = await mountSheet(OffPlanSheet, { date: '2026-09-23', time: '12:00', onSaved: vi.fn() });
        await fill(second.wrapper, { kcal: '150' });
        await second.wrapper.get('[data-save]').trigger('click');
        await flushPromises();

        expect(sent().id).not.toBe(firstId);
    });

    it.each(['1,200', '2,500'])('DAY-001 keeps Save off for %s, which could be a thousand or a decimal', async (typed) => {
        const { wrapper } = await mountSheet(OffPlanSheet, { date: '2026-09-23', time: '12:00' });

        await fill(wrapper, { kcal: typed });

        expect(wrapper.get('[data-save]').attributes('disabled')).toBeDefined();
    });

    it('CAP-014 the entry cannot change while a save that got no answer may have worked', async () => {
        writeMock.mockRejectedValueOnce(new CapabilityError('off_plan_log', 0, { reason: 'timeout' }));
        const { wrapper } = await mountSheet(OffPlanSheet, { date: '2026-09-23', time: '12:00' });

        await fill(wrapper, { kcal: '320' });
        await wrapper.get('[data-save]').trigger('click');
        await flushPromises();

        for (const field of ['name', 'time', 'kcal', 'protein', 'fat', 'carbs']) {
            expect(wrapper.get(`input[name="${field}"]`).element.matches(':disabled'), field).toBe(true);
        }
    });

    it('CAP-019 a retry after no answer sends the same entry again', async () => {
        writeMock.mockRejectedValueOnce(new CapabilityError('off_plan_log', 0, { reason: 'timeout' })).mockResolvedValueOnce({ invalidates: [] });
        const { wrapper } = await mountSheet(OffPlanSheet, { date: '2026-09-23', time: '12:00' });

        await fill(wrapper, { kcal: '320' });
        await wrapper.get('[data-save]').trigger('click');
        await flushPromises();
        const first = sent();
        await wrapper.get('[data-save]').trigger('click');
        await flushPromises();

        expect(sent().id).toBe(first.id);
        const keys = writeMock.mock.calls.map((call) => call[2]?.idempotencyKey);
        expect(keys[1]).toBe(keys[0]);
    });

    it('CAP-012 shows the server\'s words when it refuses, and stays open', async () => {
        writeMock.mockRejectedValueOnce(new CapabilityError('off_plan_log', 422, { ok: false, error: { message: 'The time must be HH:MM.' } }));
        const onSaved = vi.fn();
        const { wrapper } = await mountSheet(OffPlanSheet, { date: '2026-09-23', time: '12:00', onSaved });

        await wrapper.get('[data-save]').trigger('click');
        await flushPromises();

        expect(wrapper.get('[role="alert"]').text()).toBe('The time must be HH:MM.');
        expect(onSaved).not.toHaveBeenCalled();
    });

    it('UI-005 Cancel closes the sheet without saving', async () => {
        const onClose = vi.fn();
        const { wrapper } = await mountSheet(OffPlanSheet, { date: '2026-09-23', onClose });

        await wrapper.get('[data-cancel]').trigger('click');

        expect(onClose).toHaveBeenCalledOnce();
        expect(writeMock).not.toHaveBeenCalled();
    });
});
