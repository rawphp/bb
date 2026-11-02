import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { CapabilityError, read, writeNow } from '@/app/call';
import type { TodayShowOutput } from '@/contracts/today_show';
import { inputViolations, screenFixture } from '@/testing/schemas';
import TodayMissedDaySheet from './TodayMissedDaySheet.vue';

vi.mock('@/app/call', async (original) => ({ ...(await original<typeof import('@/app/call')>()), read: vi.fn(), writeNow: vi.fn() }));

const readMock = vi.mocked(read);
const writeMock = vi.mocked(writeNow);
const today = screenFixture<TodayShowOutput>('today_show');
const empty = screenFixture<TodayShowOutput>('today_show.empty');
const missed = today.missed_session!;

// The sheet teleports to <body>; render it in place.
const global = { stubs: { teleport: true } };

function show(props: Record<string, unknown> = { payload: today }) {
    return mount(TodayMissedDaySheet, { props, global });
}

function sent(): Record<string, unknown> {
    const call = writeMock.mock.calls.filter(([name]) => name === 'missed_session_resolve').at(-1)!;
    expect(inputViolations('missed_session_resolve', call[1])).toEqual([]);
    return call[1] as Record<string, unknown>;
}

describe('TodayMissedDaySheet', () => {
    beforeEach(() => {
        readMock.mockReset().mockResolvedValue(empty);
        writeMock.mockReset().mockResolvedValue({});
    });

    it('SCH-057 asks what to do with the missed session, offering the server\'s choices in order', () => {
        const wrapper = show();

        expect(wrapper.get('[role="dialog"] h2').text()).toBe('You missed Lower A');
        expect(wrapper.get('[role="dialog"]').text()).toContain(missed.text);
        expect(wrapper.findAll('[data-test="choice"]').map((b) => b.text())).toEqual(['Do it on the next rest day', 'Skip it']);
        expect(readMock).not.toHaveBeenCalled();
    });

    it('SCH-057 moves the session to the day the choice names', async () => {
        const wrapper = show();

        await wrapper.findAll('[data-test="choice"]')[0]!.trigger('click');
        await flushPromises();

        expect(sent()).toEqual({ mesocycle_id: missed.mesocycle_id, week: 2, day: 1, choice: 'next_rest_day', date: '2026-09-24' });
        expect(wrapper.emitted('close')).toHaveLength(1);
    });

    it('SCH-057 skips the session with no date', async () => {
        const wrapper = show();

        await wrapper.findAll('[data-test="choice"]')[1]!.trigger('click');
        await flushPromises();

        expect(sent()).toEqual({ mesocycle_id: missed.mesocycle_id, week: 2, day: 1, choice: 'skip', date: null });
    });

    it('SCH-057 shows nothing when no session was missed', () => {
        const wrapper = show({ payload: empty });

        expect(wrapper.find('[role="dialog"]').exists()).toBe(false);
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

    it('CAP-012 keeps the sheet open with the server\'s words when the choice is refused', async () => {
        writeMock.mockRejectedValueOnce(new CapabilityError('missed_session_resolve', 422, { ok: false, error: { message: 'That day is full.' } }));
        const wrapper = show();

        await wrapper.findAll('[data-test="choice"]')[0]!.trigger('click');
        await flushPromises();

        expect(wrapper.get('[role="dialog"] [role="alert"]').text()).toContain('That day is full.');
        expect(wrapper.emitted('close')).toBeUndefined();
    });

    it('UI-022 cannot be dismissed while its write is on the way', async () => {
        writeMock.mockReturnValue(new Promise(() => undefined));
        const wrapper = show();

        await wrapper.findAll('[data-test="choice"]')[1]!.trigger('click');
        await wrapper.get('[data-scrim]').trigger('click');

        expect(wrapper.emitted('close')).toBeUndefined();
        expect(wrapper.find('[role="dialog"]').exists()).toBe(true);
    });
});
