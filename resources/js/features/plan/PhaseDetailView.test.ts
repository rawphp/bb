import { flushPromises, mount, RouterLinkStub, type VueWrapper } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { CapabilityError, read, readFresh, write } from '@/app/call';
import type { PhaseShowOutput } from '@/contracts/phase_show';
import type { PlanShowOutput } from '@/contracts/plan_show';
import { inputViolations, screenFixture } from '@/testing/schemas';
import CurrentPhaseView from './CurrentPhaseView.vue';
import PhaseDetailView from './PhaseDetailView.vue';

vi.mock('@/app/call', async (original) => ({ ...(await original<typeof import('@/app/call')>()), read: vi.fn(), readFresh: vi.fn(), write: vi.fn() }));
const push = vi.fn();
vi.mock('vue-router', async (original) => ({ ...(await original<typeof import('vue-router')>()), useRouter: () => ({ push }) }));

const readMock = vi.mocked(read);
const writeMock = vi.mocked(write);
const phase = screenFixture<PhaseShowOutput>('phase_show');
const future = screenFixture<PhaseShowOutput>('phase_show.empty');
const warning = screenFixture<PhaseShowOutput>('phase_show.warning');
const plan = screenFixture<PlanShowOutput>('plan_show');
const global = { stubs: { RouterLink: RouterLinkStub, teleport: true } };
const ID = '01JB8Q2V6N3XKZ4M5P7R9T1W2A';

function show(props: Record<string, unknown> = { payload: phase }) {
    return mount(PhaseDetailView, { props, attachTo: document.body, global });
}

/** Each write sent, as [name, input], checked against that write's schema snapshot. */
function sent(): [string, Record<string, unknown>][] {
    return writeMock.mock.calls.map(([name, input]) => {
        expect(inputViolations(name, input), name).toEqual([]);
        return [name, input as Record<string, unknown>];
    });
}

const rows = (wrapper: ReturnType<typeof show>) => wrapper.findAll('[data-test="row"]').map((row) => row.text());
const pick = async (wrapper: Pick<VueWrapper, 'findAll'>, label: string) => {
    const choice = wrapper.findAll('[role="radio"]').find((r) => r.text().startsWith(label));
    await choice!.trigger('click');
};

beforeEach(() => {
    readMock.mockReset().mockResolvedValue(phase);
    vi.mocked(readFresh).mockReset().mockResolvedValue(phase);
    writeMock.mockReset().mockResolvedValue({});
    push.mockReset();
    document.body.innerHTML = '';
});

describe('PhaseDetailView', () => {
    it('PLAN-004 shows the phase\'s type, dates, rate, start and target weight and progress so far', () => {
        const wrapper = show();

        expect(wrapper.find('h1').text()).toBe('Fat loss');
        expect(wrapper.find('[data-test="dates"]').text()).toBe('31 Aug – 22 Nov');
        expect(wrapper.find('[role="progressbar"]').attributes('aria-valuenow')).toBe('50');
        expect(wrapper.find('[data-test="progress"]').text()).toBe('Week 7 of 12');
        expect(rows(wrapper)).toEqual([
            'Type Fat loss',
            'Length 12 weeks',
            'Rate Lose 0.75% a week',
            'Start weight 90.0 kg',
            'Target weight 83.2 kg',
            'Now 86.5 kg (-3.5 kg)',
        ]);
        expect(readMock).not.toHaveBeenCalled();
    });

    it('PLAN-004 leaves out the weights a phase that has not started has none of (phase_show.empty)', () => {
        const wrapper = show({ payload: future });

        expect(rows(wrapper)).toEqual(['Type Maintenance', 'Length 4 weeks', 'Rate Hold weight']);
        expect(wrapper.find('[data-test="progress"]').text()).toBe('Starts in 6 weeks');
    });

    it('PLAN-004 reads phase_show for the phase it opens', async () => {
        const wrapper = show({ phaseId: ID });
        expect(wrapper.find('[data-test="skeleton"]').exists()).toBe(true);
        await flushPromises();

        expect(readMock).toHaveBeenCalledWith('phase_show', { phase_id: ID }, expect.any(Function), expect.any(Function));
        expect(wrapper.find('h1').text()).toBe('Fat loss');
    });

    it('PH-006 lists the lengths the phase may take, the current one picked, with nothing to save', async () => {
        const wrapper = show();
        await wrapper.find('[data-test="adjust-length"]').trigger('click');

        const sheet = wrapper.find('[data-test="length-sheet"]');
        expect(sheet.findAll('[role="radio"]').map((r) => r.text())).toEqual([
            '10 weeksEnds 8 Nov',
            '11 weeksEnds 15 Nov',
            '12 weeksEnds 22 Nov',
            '16 weeksEnds 20 Dec',
        ]);
        expect(sheet.find('[role="radio"][aria-checked="true"]').text()).toContain('12 weeks');
        expect(wrapper.find('[data-save]').attributes('disabled')).toBeDefined();
    });

    it('PH-006 PLAN-021 a length that ends mid-mesocycle shows its warnings and each fix, and a fix saves it', async () => {
        const wrapper = show();
        await wrapper.find('[data-test="adjust-length"]').trigger('click');
        await pick(wrapper, '16 weeks');

        const sheet = wrapper.find('[data-test="length-sheet"]');
        expect(sheet.text()).toContain('Fat loss over 12 weeks is a long diet. Consider a shorter phase.');
        expect(sheet.text()).toContain('This ends part-way through a mesocycle');
        expect(wrapper.find('[data-save]').exists()).toBe(false);
        expect(sheet.findAll('[data-test="fix"]').map((f) => f.text())).toEqual(['Lengthen Meso 2 to 8 weeks so its deload is the last week', 'Keep 20 Dec as the end']);

        await sheet.findAll('[data-test="fix"]')[0]!.trigger('click');
        await flushPromises();

        expect(sent()).toEqual([['phase_length_set', { phase_id: ID, weeks: 16, fix: 'change_mesocycle_length' }]]);
        expect(wrapper.find('[data-test="length-sheet"]').exists()).toBe(false);
    });

    it('PH-005 PH-006 a Maintenance phase saves a new length or no end (phase_show.empty)', async () => {
        const wrapper = show({ payload: future });
        await wrapper.find('[data-test="adjust-length"]').trigger('click');
        await pick(wrapper, 'No end');
        await wrapper.find('[data-save]').trigger('click');
        await flushPromises();

        expect(sent()).toEqual([['phase_length_set', { phase_id: future.id, weeks: null, fix: 'keep_end' }]]);
    });

    it('CAP-012 keeps the length sheet open with the server\'s refusal', async () => {
        writeMock.mockRejectedValueOnce(new CapabilityError('phase_length_set', 422, { error: { code: 'validation_failed', message: 'That length is no longer allowed.', violations: [] } }));
        const wrapper = show({ payload: future });
        await wrapper.find('[data-test="adjust-length"]').trigger('click');
        await pick(wrapper, '2 weeks');
        await wrapper.find('[data-save]').trigger('click');
        await flushPromises();

        expect(wrapper.find('[data-test="length-sheet"]').text()).toContain('That length is no longer allowed.');
    });

    it('PH-007 asks before ending the phase, then ends it and goes to the plan for the transition', async () => {
        const wrapper = show();
        await wrapper.find('[data-test="end-phase"]').trigger('click');
        expect(wrapper.find('[data-test="end-sheet"]').text()).toContain('Fat loss ends this week. This cannot be undone.');
        expect(writeMock).not.toHaveBeenCalled();

        await wrapper.find('[data-save]').trigger('click');
        await flushPromises();

        expect(sent()).toEqual([['phase_end', { phase_id: ID }]]);
        expect(push).toHaveBeenCalledWith('/plan');
    });

    it('CAP-012 PH-007 stays on the phase with the server\'s refusal when it cannot end', async () => {
        writeMock.mockRejectedValueOnce(new CapabilityError('phase_end', 422, { error: { code: 'validation_failed', message: 'This phase cannot end yet.', violations: [] } }));
        const wrapper = show();
        await wrapper.find('[data-test="end-phase"]').trigger('click');
        await wrapper.find('[data-save]').trigger('click');
        await flushPromises();

        expect(push).not.toHaveBeenCalled();
        expect(wrapper.find('[data-test="end-sheet"]').text()).toContain('This phase cannot end yet.');
    });

    it('UI-022 keeps the End phase sheet open while phase_end is in flight', async () => {
        writeMock.mockImplementationOnce(() => new Promise(() => undefined));
        const wrapper = show();
        await wrapper.find('[data-test="end-phase"]').trigger('click');
        await wrapper.find('[data-save]').trigger('click');
        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
        await flushPromises();

        expect(wrapper.find('[data-test="end-sheet"]').exists()).toBe(true);
    });

    it('PH-007 sends nothing when the user keeps going', async () => {
        const wrapper = show();
        await wrapper.find('[data-test="end-phase"]').trigger('click');
        await wrapper.find('[data-cancel]').trigger('click');

        expect(wrapper.find('[data-test="end-sheet"]').exists()).toBe(false);
        expect(writeMock).not.toHaveBeenCalled();
    });

    it('PH-007 offers no End phase for a phase that is not running (phase_show.empty)', () => {
        const wrapper = show({ payload: future });

        expect(wrapper.find('[data-test="end-phase"]').exists()).toBe(false);
        expect(wrapper.find('[data-test="change-goal"]').exists()).toBe(false);
    });

    it('PH-009 previews each other goal, from next week, with the training it brings', async () => {
        const wrapper = show();
        await wrapper.find('[data-test="change-goal"]').trigger('click');

        const sheet = wrapper.find('[data-test="goal-sheet"]');
        expect(sheet.findAll('[role="radio"]').map((r) => r.text())).toEqual(['Maintenance', 'Muscle gain', 'Mini-cut']);
        expect(wrapper.find('[data-save]').attributes('disabled')).toBeDefined();

        await pick(wrapper, 'Maintenance');
        const preview = wrapper.find('[data-test="goal-preview"]');
        expect(preview.text()).toContain('From 19 Oct');
        expect(preview.text()).toContain('5 weeks');
        expect(preview.text()).toContain('Hold weight');
        expect(preview.text()).toContain('Meso 2 follows Maintenance progression from week 2.');
        expect(preview.findAll('[data-test="training-line"]').map((l) => l.text())).toContain('All muscles Maintain');
        expect(wrapper.find('[data-save]').text()).toBe('Change');
        expect(wrapper.find('[data-save]').attributes('disabled')).toBeUndefined();
    });

    it('PH-009 changes the goal with a new phase id, and a retry after no answer sends the same id', async () => {
        writeMock.mockRejectedValueOnce(new CapabilityError('phase_goal_change', 0, { reason: 'network' })).mockResolvedValueOnce({});
        const wrapper = show();
        await wrapper.find('[data-test="change-goal"]').trigger('click');
        await pick(wrapper, 'Mini-cut');
        await wrapper.find('[data-save]').trigger('click');
        await flushPromises();
        expect(wrapper.find('[data-test="goal-sheet"]').exists()).toBe(true);

        await wrapper.find('[data-save]').trigger('click');
        await flushPromises();

        const calls = sent();
        expect(calls).toHaveLength(2);
        expect(calls[0]).toEqual(['phase_goal_change', { phase_id: ID, type: 'miniCut', new_phase_id: expect.stringMatching(/^[0-9A-HJKMNP-TV-Z]{26}$/) }]);
        expect(calls[1]![1].new_phase_id).toBe(calls[0]![1].new_phase_id);
        expect(wrapper.find('[data-test="goal-sheet"]').exists()).toBe(false);
    });

    it('PH-009 makes a new phase id for a change after the server refused the last one', async () => {
        writeMock.mockRejectedValueOnce(new CapabilityError('phase_goal_change', 422, { error: { code: 'validation_failed', message: 'Not now.', violations: [] } }));
        const wrapper = show();
        await wrapper.find('[data-test="change-goal"]').trigger('click');
        await pick(wrapper, 'Mini-cut');
        await wrapper.find('[data-save]').trigger('click');
        await flushPromises();
        await pick(wrapper, 'Maintenance');
        await wrapper.find('[data-save]').trigger('click');
        await flushPromises();

        const calls = sent();
        expect(calls).toHaveLength(2);
        expect(calls[1]![1].new_phase_id).not.toBe(calls[0]![1].new_phase_id);
    });

    it('PH-012 shows a goal\'s warnings without blocking, and a refusal blocks it', async () => {
        const wrapper = show();
        await wrapper.find('[data-test="change-goal"]').trigger('click');
        await pick(wrapper, 'Muscle gain');

        const preview = wrapper.find('[data-test="goal-preview"]');
        expect(preview.text()).toContain('A surplus with a low-volume plan wastes the phase.');
        expect(preview.find('[role="alert"]').text()).toBe('A Muscle gain phase must run at least 8 weeks.');
        expect(wrapper.find('[data-save]').attributes('disabled')).toBeDefined();
    });

    it('PH-043 adds an event date to the phase', async () => {
        const wrapper = show();
        await wrapper.find('[data-test="add-event"]').trigger('click');
        await wrapper.find('input[type="date"]').setValue('2026-11-15');
        await wrapper.find('[data-save]').trigger('click');
        await flushPromises();

        expect(sent()).toEqual([['phase_event_date_set', { phase_id: ID, event_date: '2026-11-15' }]]);
    });

    it('PH-042 PH-043 shows the event, its countdown and the warning, and removes it (phase_show.warning)', async () => {
        const wrapper = show({ payload: warning });

        expect(wrapper.find('[data-test="event"]').text()).toContain('2026-11-15');
        expect(wrapper.find('[data-test="event"]').text()).toContain('31 days to your event');
        expect(wrapper.find('[data-test="warnings"]').text()).toBe('This phase ends after your event on 15 Nov.');

        await wrapper.find('[data-test="remove-event"]').trigger('click');
        await flushPromises();
        expect(sent()).toEqual([['phase_event_date_set', { phase_id: ID, event_date: null }]]);
    });

    it('CAP-012 says why the event date could not be removed', async () => {
        writeMock.mockRejectedValueOnce(new CapabilityError('phase_event_date_set', 422, { error: { code: 'validation_failed', message: 'The event has passed.', violations: [] } }));
        const wrapper = show({ payload: warning });
        await wrapper.find('[data-test="remove-event"]').trigger('click');
        await flushPromises();

        expect(wrapper.find('[data-test="error"]').text()).toContain('The event has passed.');
    });

    it('PLAN-028 offers to end the phase in the event week, opening the lengths at that week (phase_show.warning)', async () => {
        const wrapper = show({ payload: warning });

        expect(wrapper.find('[data-test="event-offer"]').text()).toBe('End the phase in your event week, on 15 Nov?');
        await wrapper.find('[data-test="event-offer"]').trigger('click');

        const sheet = wrapper.find('[data-test="length-sheet"]');
        expect(sheet.find('[role="radio"][aria-checked="true"]').text()).toContain('11 weeks');
        expect(sheet.findAll('[data-test="fix"]').map((f) => f.text())).toContain('Keep 15 Nov as the end');
    });

    it('OFF-008 marks the phase\'s server numbers waiting to sync and dims them', () => {
        const wrapper = show({ payload: phase, pending: true });

        expect(wrapper.findAll('[data-mark]')).toHaveLength(1);
        expect(wrapper.find('[data-value]').text()).toBe('Week 7 of 12');
        expect(wrapper.find('[data-test="rows"]').classes()).toContain('opacity-60');
    });

    it('OFF-008 shows no mark when nothing waits to sync', () => {
        const wrapper = show();

        expect(wrapper.find('[data-mark]').exists()).toBe(false);
        expect(wrapper.find('.opacity-60').exists()).toBe(false);
    });

    it('CAP-002 says why the phase failed to load and tries again', async () => {
        readMock.mockReset().mockRejectedValueOnce(new CapabilityError('phase_show', 500, null)).mockResolvedValueOnce(phase);
        const wrapper = show({ phaseId: ID });
        await flushPromises();

        expect(wrapper.text()).toContain('This phase could not be loaded.');
        await wrapper.find('[data-test="retry"]').trigger('click');
        await flushPromises();
        expect(wrapper.find('h1').text()).toBe('Fat loss');
    });

    it('CAP-004 says when the user is signed out', async () => {
        readMock.mockReset().mockRejectedValueOnce(new CapabilityError('phase_show', 401, null));
        const wrapper = show({ phaseId: ID });
        await flushPromises();

        expect(wrapper.text()).toContain('You are signed out.');
    });
});

describe('CurrentPhaseView', () => {
    function current(props: Record<string, unknown>) {
        return mount(CurrentPhaseView, { props, attachTo: document.body, global });
    }

    it('PH-006 opens the running phase with its lengths already showing', async () => {
        const wrapper = current({ payload: plan, action: 'adjust-length' });
        await flushPromises();

        expect(readMock).toHaveBeenCalledWith('phase_show', { phase_id: ID }, expect.any(Function), expect.any(Function));
        expect(wrapper.find('[data-test="length-sheet"]').exists()).toBe(true);
    });

    it('PH-007 opens the running phase with End phase already asking', async () => {
        const wrapper = current({ payload: plan, action: 'end' });
        await flushPromises();

        expect(wrapper.find('[data-test="end-sheet"]').exists()).toBe(true);
    });

    it('PH-007 opens no End phase sheet when the server says the running phase cannot end', async () => {
        readMock.mockResolvedValue({ ...phase, can_end: false });
        const wrapper = current({ payload: plan, action: 'end' });
        await flushPromises();

        expect(wrapper.find('h1').text()).toBe('Fat loss');
        expect(wrapper.find('[data-test="end-sheet"]').exists()).toBe(false);
    });

    it('PH-006 opens the length sheet once, so a saved length closes it for good', async () => {
        const wrapper = current({ payload: plan, action: 'adjust-length' });
        await flushPromises();
        await pick(wrapper, '16 weeks');
        await wrapper.findAll('[data-test="fix"]')[0]!.trigger('click');
        await flushPromises();

        expect(readMock).toHaveBeenCalledTimes(2);
        expect(wrapper.find('[data-test="length-sheet"]').exists()).toBe(false);
    });

    it('PH-006 says no phase is running when there is none (plan_show.empty)', () => {
        const wrapper = current({ payload: screenFixture<PlanShowOutput>('plan_show.empty'), action: 'adjust-length' });

        expect(wrapper.find('h1').text()).toBe('Adjust phase length');
        expect(wrapper.text()).toContain('No phase is running.');
        expect(readMock).not.toHaveBeenCalled();
    });

    it('CAP-002 shows a skeleton while plan_show loads, then why it failed', async () => {
        readMock.mockReset().mockRejectedValueOnce(new CapabilityError('plan_show', 500, null));
        const wrapper = current({ action: 'end' });
        expect(wrapper.find('[data-test="skeleton"]').exists()).toBe(true);
        await flushPromises();

        expect(wrapper.find('h1').text()).toBe('End current phase');
        expect(wrapper.text()).toContain('Your plan could not be loaded.');
    });
});
