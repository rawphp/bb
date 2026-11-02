import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { CapabilityError, read, write } from '@/app/call';
import type { AwayWeekShowOutput } from '@/contracts/away_week_show';
import { inputViolations, screenFixture } from '@/testing/schemas';
import AwayWeekSheet from './AwayWeekSheet.vue';

vi.mock('@/app/call', async (original) => ({ ...(await original<typeof import('@/app/call')>()), read: vi.fn(), write: vi.fn() }));

const readMock = vi.mocked(read);
const writeMock = vi.mocked(write);
const marking = screenFixture<AwayWeekShowOutput>('away_week_show');
const editing = screenFixture<AwayWeekShowOutput>('away_week_show.editing');

// The sheet teleports to <body>; render it in place.
const global = { stubs: { teleport: true } };

function show(props: Record<string, unknown> = { payload: marking }) {
    return mount(AwayWeekSheet, { props, global });
}

// The last write's input, checked against its schema snapshot.
function sent(capability: string): Record<string, unknown> {
    const call = writeMock.mock.calls.filter(([name]) => name === capability).at(-1)!;
    expect(inputViolations(capability, call[1])).toEqual([]);
    return call[1] as Record<string, unknown>;
}

describe('AwayWeekSheet', () => {
    beforeEach(() => {
        readMock.mockReset().mockResolvedValue(marking);
        writeMock.mockReset().mockResolvedValue({});
    });

    it('SCH-060 shows the future plan weeks to mark, with the opening week chosen', () => {
        const wrapper = show();

        expect(wrapper.get('[role="dialog"] h2').text()).toBe('Mark weeks away');
        const weeks = wrapper.findAll('[data-test="week"]');
        expect(weeks.map((w) => w.text())).toEqual(['Week 3 · Sep 26', 'Week 4 · Oct 3', 'Week 5 · Oct 10']);
        expect(weeks.map((w) => w.attributes('aria-pressed'))).toEqual(['true', 'false', 'false']);
        expect(readMock).not.toHaveBeenCalled();
    });

    it('SCH-060 offers training and calories as the server words them, with a disabled deload and its reason', () => {
        const wrapper = show();

        const training = wrapper.get('[role="radiogroup"][aria-label="Training"]');
        const radios = training.findAll('[role="radio"]');
        expect(radios.map((r) => r.attributes('aria-label'))).toEqual(['Pause training', 'Make it the deload']);
        expect(radios[0]!.attributes('aria-checked')).toBe('true');
        expect(radios[1]!.attributes('disabled')).toBeDefined();
        expect(training.text()).toContain('Needs 3 training weeks of the mesocycle before it.');

        const calories = wrapper.get('[role="radiogroup"][aria-label="Calories"]');
        expect(calories.find('[aria-checked="true"]').attributes('aria-label')).toBe('Maintenance');
        expect(calories.text()).toContain('In Fat loss the week counts as a diet break.');
        expect(wrapper.get('[data-test="notes"]').text()).toBe('The phase ends later, a week for each diet break.');
        expect(wrapper.find('[data-test="keep-phase-end"]').exists()).toBe(true);
    });

    it('SCH-060 hides the choices until a week is chosen, and Save with them', async () => {
        const wrapper = show();

        await wrapper.findAll('[data-test="week"]')[0]!.trigger('click');

        expect(wrapper.findAll('[data-test="week"]')[0]!.attributes('aria-pressed')).toBe('false');
        expect(wrapper.find('[role="radiogroup"]').exists()).toBe(false);
        expect(wrapper.get('[data-save]').attributes('disabled')).toBeDefined();
    });

    it('SCH-060 saves every chosen week with the chosen training and calories as one away_week_set', async () => {
        const wrapper = show();

        await wrapper.findAll('[data-test="week"]')[2]!.trigger('click');
        await wrapper.get('[role="radiogroup"][aria-label="Calories"]').findAll('[role="radio"]')[0]!.trigger('click');
        await wrapper.get('[data-test="keep-phase-end"] input').setValue(true);
        await wrapper.get('[data-save]').trigger('click');
        await flushPromises();

        expect(sent('away_week_set')).toEqual({
            week_starts: ['2026-09-26', '2026-10-10'],
            away: true,
            training: 'pause',
            calories: 'rest',
            keep_phase_end: true,
        });
        expect(wrapper.emitted('close')).toHaveLength(1);
    });

    it('SCH-060 sends no keep_phase_end when the server does not offer it', async () => {
        const wrapper = show({ payload: { ...marking, shows_keep_phase_end: false, notes: [] } });

        expect(wrapper.find('[data-test="keep-phase-end"]').exists()).toBe(false);
        expect(wrapper.find('[data-test="notes"]').exists()).toBe(false);
        await wrapper.get('[data-save]').trigger('click');
        await flushPromises();

        expect(sent('away_week_set')).toEqual({ week_starts: ['2026-09-26'], away: true, training: 'pause', calories: 'maintenance' });
    });

    it('SCH-060 edits an away week: names it, opens with its saved choices, and Not away clears it', async () => {
        const wrapper = show({ payload: editing });

        expect(wrapper.get('[role="dialog"] h2').text()).toBe('Edit away week');
        expect(wrapper.find('[data-test="week"]').exists()).toBe(false);
        expect(wrapper.get('[data-test="editing-week"]').text()).toBe('Week 3 · Sep 26');
        expect(wrapper.get('[role="radiogroup"][aria-label="Calories"]').find('[aria-checked="true"]').attributes('aria-label')).toBe('Rest-day targets');

        await wrapper.get('[data-test="not-away"]').trigger('click');
        await flushPromises();

        expect(sent('away_week_set')).toEqual({ week_starts: ['2026-09-26'], away: false });
        expect(wrapper.emitted('close')).toHaveLength(1);
    });

    it('SCH-060 shows an empty list when no future week can be marked', () => {
        const wrapper = show({ payload: { ...marking, weeks: [] } });

        expect(wrapper.get('[data-test="no-weeks"]').text()).toBe('No future plan weeks to mark.');
        expect(wrapper.get('[data-save]').attributes('disabled')).toBeDefined();
    });

    it('CAP-002 reads away_week_show for the week it opens on, showing a skeleton meanwhile', async () => {
        readMock.mockReturnValue(new Promise(() => undefined));
        const wrapper = show({ weekStart: '2026-09-26' });
        await flushPromises();

        expect(readMock).toHaveBeenCalledWith('away_week_show', { week_start: '2026-09-26' }, expect.any(Function), expect.any(Function));
        expect(wrapper.find('[data-test="skeleton"]').exists()).toBe(true);
        expect(wrapper.get('[data-save]').attributes('disabled')).toBeDefined();
    });

    it('CAP-002 says why the weeks did not load, with Try again', async () => {
        readMock.mockRejectedValue(new CapabilityError('away_week_show', 500, { ok: false, error: { message: 'Server error.' } }));
        const wrapper = show({ weekStart: '2026-09-26' });
        await flushPromises();

        expect(wrapper.get('[role="dialog"] [role="alert"]').text()).toContain('The away weeks could not be loaded.');
        expect(wrapper.find('[data-test="retry"]').exists()).toBe(true);
    });

    it('CAP-012 keeps the sheet open with the server\'s words when the save is refused', async () => {
        writeMock.mockRejectedValueOnce(new CapabilityError('away_week_set', 422, { ok: false, error: { message: 'That week has started.' } }));
        const wrapper = show();

        await wrapper.get('[data-save]').trigger('click');
        await flushPromises();

        expect(wrapper.get('[role="dialog"] [role="alert"]').text()).toContain('That week has started.');
        expect(wrapper.emitted('close')).toBeUndefined();
    });

    it('UI-005 closes on Cancel without a write', async () => {
        const wrapper = show();

        await wrapper.findAll('[role="dialog"] header button')[0]!.trigger('click');

        expect(wrapper.emitted('close')).toHaveLength(1);
        expect(writeMock).not.toHaveBeenCalled();
    });
});
