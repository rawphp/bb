import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { CapabilityError, read, write } from '@/app/call';
import type { TodayShowOutput } from '@/contracts/today_show';
import { inputViolations, screenFixture } from '@/testing/schemas';
import TodayMissedWeeksSheet from './TodayMissedWeeksSheet.vue';

vi.mock('@/app/call', async (original) => ({ ...(await original<typeof import('@/app/call')>()), read: vi.fn(), write: vi.fn() }));

const readMock = vi.mocked(read);
const writeMock = vi.mocked(write);
const today = screenFixture<TodayShowOutput>('today_show');
const empty = screenFixture<TodayShowOutput>('today_show.empty');
const missed = today.missed_weeks!;

// The sheet teleports to <body>; render it in place.
const global = { stubs: { teleport: true } };

function show(props: Record<string, unknown> = { payload: today }) {
    return mount(TodayMissedWeeksSheet, { props, global });
}

function sent(): Record<string, unknown> {
    const call = writeMock.mock.calls.filter(([name]) => name === 'missed_week_resolve').at(-1)!;
    expect(inputViolations('missed_week_resolve', call[1])).toEqual([]);
    return call[1] as Record<string, unknown>;
}

describe('TodayMissedWeeksSheet', () => {
    beforeEach(() => {
        readMock.mockReset().mockResolvedValue(empty);
        writeMock.mockReset().mockResolvedValue({});
    });

    it('SCH-058 asks to restart the week or end the mesocycle, in the server\'s words', () => {
        const wrapper = show();

        expect(wrapper.get('[role="dialog"] h2').text()).toBe('Welcome back');
        expect(wrapper.get('[role="dialog"]').text()).toContain(missed.text);
        expect(wrapper.get('[data-test="restart"]').text()).toBe('Restart this week');
        expect(wrapper.get('[data-test="end"]').text()).toBe('End it and build a new one');
        expect(readMock).not.toHaveBeenCalled();
    });

    it('SCH-058 restarts the week with missed_week_resolve', async () => {
        const wrapper = show();

        await wrapper.get('[data-test="restart"]').trigger('click');
        await flushPromises();

        expect(sent()).toEqual({ mesocycle_id: missed.mesocycle_id, choice: 'restart' });
        expect(wrapper.emitted('close')).toHaveLength(1);
    });

    it('SCH-058 warns before ending, and offers the diet break the server offers', async () => {
        const wrapper = show();

        await wrapper.get('[data-test="end"]').trigger('click');
        expect(wrapper.get('[data-test="end-warning"]').text()).toBe(missed.end_warning);
        expect(writeMock).not.toHaveBeenCalled();

        await wrapper.get('[data-test="end-with-break"]').trigger('click');
        await flushPromises();
        expect(sent()).toEqual({ mesocycle_id: missed.mesocycle_id, choice: 'end', diet_break: true });
    });

    it('SCH-058 ends without a diet break when the user picks that', async () => {
        const wrapper = show();

        await wrapper.get('[data-test="end"]').trigger('click');
        await wrapper.get('[data-test="end-without-break"]').trigger('click');
        await flushPromises();

        expect(sent()).toEqual({ mesocycle_id: missed.mesocycle_id, choice: 'end', diet_break: false });
    });

    it('SCH-058 offers only End when there is no week to restart and no diet break', async () => {
        const wrapper = show({ payload: { ...today, missed_weeks: { ...missed, can_restart: false, offers_diet_break: false } } });

        expect(wrapper.find('[data-test="restart"]').exists()).toBe(false);
        await wrapper.get('[data-test="end"]').trigger('click');
        expect(wrapper.find('[data-test="end-with-break"]').exists()).toBe(false);
        await wrapper.get('[data-test="end-confirm"]').trigger('click');
        await flushPromises();

        expect(sent()).toEqual({ mesocycle_id: missed.mesocycle_id, choice: 'end' });
    });

    it('SCH-058 shows nothing when no weeks were missed', () => {
        const wrapper = show({ payload: empty });

        expect(wrapper.find('[role="dialog"]').exists()).toBe(false);
    });

    it('SCH-058 closes on Not now without a write', async () => {
        const wrapper = show();

        await wrapper.findAll('[role="dialog"] header button')[0]!.trigger('click');

        expect(wrapper.emitted('close')).toHaveLength(1);
        expect(wrapper.find('[role="dialog"]').exists()).toBe(false);
        expect(writeMock).not.toHaveBeenCalled();
    });

    it('CAP-002 reads today_show, showing a skeleton meanwhile', async () => {
        readMock.mockReturnValue(new Promise(() => undefined));
        const wrapper = show({});
        await flushPromises();

        expect(readMock).toHaveBeenCalledWith('today_show', {}, expect.any(Function), expect.any(Function));
        expect(wrapper.find('[data-test="skeleton"]').exists()).toBe(true);
    });

    it('CAP-002 says why Today did not load, with Try again', async () => {
        readMock.mockRejectedValue(new CapabilityError('today_show', 500, null));
        const wrapper = show({});
        await flushPromises();

        expect(wrapper.get('[role="dialog"] [role="alert"]').text()).toContain('Today could not be loaded.');
        expect(wrapper.find('[data-test="retry"]').exists()).toBe(true);
    });

    it('CAP-012 keeps the sheet open with the server\'s words when the answer is refused', async () => {
        writeMock.mockRejectedValueOnce(new CapabilityError('missed_week_resolve', 422, { ok: false, error: { message: 'This mesocycle has ended.' } }));
        const wrapper = show();

        await wrapper.get('[data-test="restart"]').trigger('click');
        await flushPromises();

        expect(wrapper.get('[role="dialog"] [role="alert"]').text()).toContain('This mesocycle has ended.');
        expect(wrapper.emitted('close')).toBeUndefined();
    });
});
