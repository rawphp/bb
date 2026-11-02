import { flushPromises } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { CapabilityError, read, write } from '@/app/call';
import type { DayOptimizeShowOutput } from '@/contracts/day_optimize_show';
import { inputViolations, screenFixture } from '@/testing/schemas';
import TodayOptimizeSheet from './TodayOptimizeSheet.vue';
import { mountSheet } from './sheetTesting';

vi.mock('@/app/call', async (original) => ({ ...(await original<typeof import('@/app/call')>()), read: vi.fn(), write: vi.fn() }));

const readMock = vi.mocked(read);
const writeMock = vi.mocked(write);
const content = screenFixture<DayOptimizeShowOutput>('day_optimize_show');
const empty = screenFixture<DayOptimizeShowOutput>('day_optimize_show.empty');

function rowText(wrapper: Awaited<ReturnType<typeof mountSheet>>['wrapper'], name: string): string {
    const row = wrapper.findAll('[data-test="optimize-row"]').find((r) => r.text().startsWith(name));
    expect(row, name).toBeDefined();
    // Each piece of text in the row, in order, as a screen reader reads it.
    const parts: string[] = [];
    const walker = document.createTreeWalker(row!.element, NodeFilter.SHOW_TEXT);
    while (walker.nextNode()) {
        const text = walker.currentNode.textContent!.trim();
        if (text) parts.push(text);
    }
    return parts.join(' ');
}

describe('TodayOptimizeSheet', () => {
    beforeEach(() => {
        readMock.mockReset();
        writeMock.mockReset();
        readMock.mockResolvedValue(content);
    });

    it('SCH-036 shows each meal\'s time before and after, before saving', async () => {
        const { wrapper } = await mountSheet(TodayOptimizeSheet, { payload: content });

        expect(wrapper.text()).toContain('Optimize schedule');
        expect(wrapper.findAll('[data-test="optimize-row"]')).toHaveLength(content.rows.length);
        expect(rowText(wrapper, 'Breakfast')).toBe('Breakfast 8:00 AM');
        expect(rowText(wrapper, 'Lunch')).toBe('Lunch 12:30 PM to 12:00 PM');
        expect(rowText(wrapper, 'Intra-workout shake')).toBe('Intra-workout shake New · 6:00 PM');
        expect(readMock).not.toHaveBeenCalled();
    });

    it('SCH-036 marks a meal the result drops as removed', async () => {
        const dropped = { ...content, rows: [{ meal_id: 'm', name: 'Snack', before_text: '3:00 PM', after_text: null, changed: true }] };
        const { wrapper } = await mountSheet(TodayOptimizeSheet, { payload: dropped });

        expect(rowText(wrapper, 'Snack')).toBe('Snack 3:00 PM to Removed');
    });

    it('SCH-036 says when the meals are already in the best places, and keeps Save off', async () => {
        const { wrapper } = await mountSheet(TodayOptimizeSheet, { payload: empty });

        expect(wrapper.text()).toContain('Your meals are already in the best places.');
        expect(wrapper.get('[data-save]').attributes('disabled')).toBeDefined();
    });

    it('SCH-036 saves the new times for the day and hands the server\'s toast to the screen', async () => {
        const result = { invalidates: [], toast: 'Schedule optimized', undo: '01JBX0U1V2W3X4Y5Z6A7B8C9D0' };
        writeMock.mockResolvedValueOnce(result);
        const onSaved = vi.fn();
        const { wrapper } = await mountSheet(TodayOptimizeSheet, { payload: content, onSaved });

        await wrapper.get('[data-save]').trigger('click');
        await flushPromises();

        const [name, input] = writeMock.mock.calls.at(-1)!;
        expect(name).toBe('day_optimize');
        expect(inputViolations('day_optimize', input)).toEqual([]);
        expect(input).toEqual({ date: '2026-09-23' });
        expect(onSaved).toHaveBeenCalledWith(result);
    });

    it('CAP-012 shows the server\'s words when it refuses, and stays open', async () => {
        writeMock.mockRejectedValueOnce(new CapabilityError('day_optimize', 422, { ok: false, error: { message: 'This day has a checked-in meal.' } }));
        const { wrapper } = await mountSheet(TodayOptimizeSheet, { payload: content });

        await wrapper.get('[data-save]').trigger('click');
        await flushPromises();

        expect(wrapper.get('[role="alert"]').text()).toBe('This day has a checked-in meal.');
    });

    it('UI-005 Cancel closes the sheet without saving', async () => {
        const onClose = vi.fn();
        const { wrapper } = await mountSheet(TodayOptimizeSheet, { payload: content, onClose });

        await wrapper.get('[data-cancel]').trigger('click');

        expect(onClose).toHaveBeenCalledOnce();
        expect(writeMock).not.toHaveBeenCalled();
    });

    it('OFF-003 reads the day\'s proposal, with a skeleton while it loads', async () => {
        readMock.mockReturnValueOnce(new Promise(() => {}));
        const { wrapper } = await mountSheet(TodayOptimizeSheet, { date: '2026-09-23' });
        await flushPromises();

        const [name, input] = readMock.mock.calls.at(-1)!;
        expect(name).toBe('day_optimize_show');
        expect(input).toEqual({ date: '2026-09-23' });
        expect(wrapper.find('[data-test="skeleton"]').exists()).toBe(true);
        expect(wrapper.get('[data-save]').attributes('disabled')).toBeDefined();
    });

    it('OFF-003 says the sheet could not load, with Try again', async () => {
        readMock.mockRejectedValueOnce(new CapabilityError('day_optimize_show', 500, null));
        const { wrapper } = await mountSheet(TodayOptimizeSheet, { date: '2026-09-23' });
        await flushPromises();

        expect(wrapper.text()).toContain('The new schedule could not be loaded.');
        await wrapper.get('[data-test="retry"]').trigger('click');
        await flushPromises();
        expect(wrapper.findAll('[data-test="optimize-row"]')).toHaveLength(content.rows.length);
    });

    it('OFF-008 dims the proposed times while a change waits to sync', async () => {
        const { wrapper } = await mountSheet(TodayOptimizeSheet, { payload: content, pending: true });

        expect(wrapper.findAll('[data-mark]')).toHaveLength(content.rows.length);
        expect(wrapper.findAll('[data-value]').every((v) => v.classes('opacity-60'))).toBe(true);
    });
});
