import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { CapabilityError, read, readFresh, write } from '@/app/call';
import type { PlanShowOutput } from '@/contracts/plan_show';
import { screenFixture } from '@/testing/schemas';
import PhaseTransitionView from './PhaseTransitionView.vue';
import { plan, sent } from './planTesting';

vi.mock('@/app/call', async (original) => ({ ...(await original<typeof import('@/app/call')>()), read: vi.fn(), readFresh: vi.fn(), write: vi.fn() }));
const replace = vi.fn();
vi.mock('vue-router', async (original) => ({ ...(await original<typeof import('vue-router')>()), useRouter: () => ({ replace }) }));

const ending = plan('warning');

function show(props: Record<string, unknown> = { payload: ending }) {
    return mount(PhaseTransitionView, { props, attachTo: document.body, global: { stubs: { teleport: true } } });
}

const rows = (wrapper: ReturnType<typeof show>, test: string) => wrapper.findAll(`[data-test="${test}"] li`).map((row) => row.text());
const radios = (wrapper: ReturnType<typeof show>, test: string) => wrapper.findAll(`[data-test="${test}"] [role="radio"]`);

async function pick(wrapper: ReturnType<typeof show>, test: string, index: number): Promise<void> {
    await radios(wrapper, test)[index]!.trigger('click');
}

async function confirm(wrapper: ReturnType<typeof show>): Promise<void> {
    await wrapper.find('[data-save]').trigger('click');
    await flushPromises();
}

describe('PhaseTransitionView', () => {
    beforeEach(() => {
        vi.mocked(read).mockReset().mockResolvedValue(ending);
        vi.mocked(readFresh).mockReset().mockResolvedValue(ending);
        vi.mocked(write).mockReset().mockResolvedValue({});
        replace.mockReset();
    });

    it('PH-030 sums up the phase that ended: length, weight change, rate, adherence and mesocycles', () => {
        const wrapper = show();

        expect(wrapper.find('[role="dialog"]').text()).toContain('End Fat loss');
        expect(rows(wrapper, 'summary')).toEqual(['Length7 weeks', 'Weight change-3.5 kg', 'Rate achieved-0.56% a week', 'Adherence87%', 'Completed1 mesocycle']);
        expect(read).not.toHaveBeenCalled();
    });

    it('PH-030 offers the next phases with the recommended one picked, and the choice of another', async () => {
        const wrapper = show();

        expect(wrapper.text()).toContain('What comes next, from 19 Oct');
        const options = radios(wrapper, 'options');
        expect(options.map((o) => o.attributes('aria-label'))).toEqual(['Maintenance, recommended', 'Gain first, recommended', 'Fat loss']);
        expect(options[0]!.attributes('aria-checked')).toBe('true');

        await pick(wrapper, 'options', 2);
        expect(radios(wrapper, 'options')[2]!.attributes('aria-checked')).toBe('true');
    });

    it('PH-031 offers the server\'s Maintenance lengths with its default picked', async () => {
        const wrapper = show();

        const lengths = radios(wrapper, 'lengths');
        expect(lengths.map((l) => l.text())).toEqual(['5 weeksEnds 22 Nov', '7 weeksEnds 6 Dec', '14 weeksEnds 24 Jan']);
        expect(lengths[0]!.attributes('aria-checked')).toBe('true');
    });

    it('PH-035 shows the starting calories from measured expenditure, and PH-037 the weight to hold', () => {
        const wrapper = show();

        expect(wrapper.find('[data-test="calories"]').text()).toBe('Starts from your measured expenditure of 2,650 kcal a day.');
        expect(wrapper.find('[data-test="corridor"]').text()).toContain('Hold your new weight of about 86.5 kg');
    });

    it('PH-044 steps calories up by default, shows each week, and can go straight to maintenance', async () => {
        const wrapper = show();

        expect(wrapper.find('[data-test="step-up"]').text()).toContain('A 12-week diet is a 16-week process.');
        expect(rows(wrapper, 'schedule')[0]).toBe('Week 12,350 kcal');
        await pick(wrapper, 'steps', 1);
        expect(rows(wrapper, 'schedule')[0]).toBe('Week 12,600 kcal');

        await pick(wrapper, 'calorie-mode', 1);
        expect(wrapper.find('[data-test="steps"]').exists()).toBe(false);
        expect(wrapper.find('[data-test="schedule"]').exists()).toBe(false);
        await confirm(wrapper);
        expect(sent()[0]![1]).toMatchObject({ calorie_mode: 'straight', step_kcal: null });
    });

    it('PH-036 pairs Maintenance with resensitization training, and the balanced template can replace it', async () => {
        const wrapper = show();

        expect(rows(wrapper, 'training')[0]).toBe('All muscles Maintain');
        expect(wrapper.find('[data-test="template-detail"]').text()).toContain('A resensitization mesocycle');
        await pick(wrapper, 'templates', 1);
        expect(rows(wrapper, 'training')[0]).toBe('All muscles Grow');

        await confirm(wrapper);
        expect(sent()[0]![1]).toMatchObject({ template: 'balanced' });
    });

    it('PH-030 confirms the recommended next phase with a valid phase_transition_complete, then leaves', async () => {
        const wrapper = show();
        await confirm(wrapper);

        const [[name, input]] = sent() as [[string, Record<string, unknown>]];
        expect(name).toBe('phase_transition_complete');
        expect(input).toMatchObject({
            phase_id: ending.transition!.phase_id,
            choice: 'maintenance',
            gain_first: false,
            weeks: 5,
            template: 'resensitization',
            calorie_mode: 'step_up',
            step_kcal: 250,
        });
        expect(input.next_phase_id).toMatch(/^[0-9A-HJKMNP-TV-Z]{26}$/);
        expect(replace).toHaveBeenCalledWith('/train');
    });

    it('PH-032 offers Gain first: its note, no length, and it sends gain_first', async () => {
        const wrapper = show();
        await pick(wrapper, 'options', 1);

        expect(wrapper.find('[data-test="lengths"]').exists()).toBe(false);
        expect(wrapper.find('[data-test="note"]').text()).toContain('A Muscle gain needs an end date here');
        expect(wrapper.find('[data-test="step-up"]').exists()).toBe(false);
        await confirm(wrapper);
        expect(sent()[0]![1]).toMatchObject({ choice: 'muscleGain', gain_first: true, weeks: null, template: 'standard', calorie_mode: null, step_kcal: null });
    });

    it('PH-034 shows the warning on Fat loss straight after Fat loss, and sends the length picked', async () => {
        const wrapper = show();
        await pick(wrapper, 'options', 2);

        expect(rows(wrapper, 'warnings')).toEqual(['Fat loss right after Fat loss is not recommended. Run a Maintenance phase between them.']);
        await pick(wrapper, 'lengths', 1);
        await confirm(wrapper);
        expect(sent()[0]![1]).toMatchObject({ choice: 'fatLoss', gain_first: false, weeks: 12, template: 'standard' });
    });

    it('PH-046 asks about fatigue and the calendar, warns on a no, and can switch to Maintenance first', async () => {
        const wrapper = show();
        await pick(wrapper, 'options', 2);

        const questions = wrapper.findAll('[data-test="readiness"] [role="radiogroup"]');
        expect(questions.map((q) => q.attributes('aria-label'))).toEqual([
            'Is your diet fatigue low?',
            'Is your training fatigue low?',
            'Is your calendar clear for the next phase?',
        ]);
        expect(wrapper.find('[data-test="maintenance-first"]').exists()).toBe(false);
        await questions[1]!.findAll('[role="radio"]')[1]!.trigger('click');

        expect(wrapper.find('[data-test="readiness"]').text()).toContain('Training fatigue is not low. Run Maintenance first.');
        await wrapper.find('[data-test="maintenance-first"]').trigger('click');
        expect(radios(wrapper, 'options')[0]!.attributes('aria-checked')).toBe('true');
        expect(wrapper.find('[data-test="readiness"]').exists()).toBe(false);
    });

    it('PH-030 another next phase starts from the server\'s length, template, step and calories, not the last one\'s picks', async () => {
        const wrapper = show();
        await pick(wrapper, 'templates', 1);
        await pick(wrapper, 'steps', 1);
        await pick(wrapper, 'calorie-mode', 1);
        await pick(wrapper, 'options', 2);
        await pick(wrapper, 'lengths', 1);
        await pick(wrapper, 'options', 0);

        expect(radios(wrapper, 'lengths')[0]!.attributes('aria-checked')).toBe('true');
        await confirm(wrapper);
        expect(sent()[0]![1]).toMatchObject({ choice: 'maintenance', weeks: 5, template: 'resensitization', calorie_mode: 'step_up', step_kcal: 250 });
    });

    it('PH-046 Choose Maintenance first starts Maintenance from the server\'s picks', async () => {
        const wrapper = show();
        await pick(wrapper, 'options', 2);
        await pick(wrapper, 'lengths', 1);
        await wrapper.findAll('[data-test="readiness"] [role="radiogroup"]')[0]!.findAll('[role="radio"]')[1]!.trigger('click');
        await wrapper.find('[data-test="maintenance-first"]').trigger('click');
        await confirm(wrapper);

        expect(sent()[0]![1]).toMatchObject({ choice: 'maintenance', gain_first: false, weeks: 5, template: 'resensitization' });
    });

    it('PH-030 starts from the next phase the server marks, wherever it is in the list', async () => {
        const moved = structuredClone(ending);
        moved.transition!.options[0]!.is_selected = false;
        moved.transition!.options[2]!.is_selected = true;
        const wrapper = show({ payload: moved });

        expect(radios(wrapper, 'options')[2]!.attributes('aria-checked')).toBe('true');
        await confirm(wrapper);
        expect(sent()[0]![1]).toMatchObject({ choice: 'fatLoss', weeks: 6 });
    });

    it('PH-031 PH-036 PH-044 start from the length, template and step the server marks', async () => {
        const moved = structuredClone(ending);
        const maintenance = moved.transition!.options[0]!;
        maintenance.lengths.forEach((l, index) => (l.is_selected = index === 1));
        maintenance.templates.forEach((t, index) => (t.is_selected = index === 1));
        maintenance.step_up!.steps.forEach((step, index) => (step.is_selected = index === 1));
        const wrapper = show({ payload: moved });

        expect(radios(wrapper, 'lengths')[1]!.attributes('aria-checked')).toBe('true');
        expect(radios(wrapper, 'templates')[1]!.attributes('aria-checked')).toBe('true');
        expect(radios(wrapper, 'steps')[1]!.attributes('aria-checked')).toBe('true');
        await confirm(wrapper);
        expect(sent()[0]![1]).toMatchObject({ weeks: 7, template: 'balanced', calorie_mode: 'step_up', step_kcal: 500 });
    });

    it('CAP-019 sends the same next_phase_id again after phase_transition_complete got no answer', async () => {
        vi.mocked(write).mockRejectedValueOnce(new CapabilityError('phase_transition_complete', 0, { reason: 'network' }));
        const wrapper = show();
        await confirm(wrapper);
        await confirm(wrapper);

        const ids = sent().map(([, input]) => input.next_phase_id);
        expect(ids).toHaveLength(2);
        expect(ids[1]).toBe(ids[0]);
    });

    it('PH-046 nothing on the sheet changes the choice while Confirm is sending', async () => {
        vi.mocked(write).mockReturnValue(new Promise(() => {}));
        const wrapper = show();
        await pick(wrapper, 'options', 2);
        await wrapper.findAll('[data-test="readiness"] [role="radiogroup"]')[0]!.findAll('[role="radio"]')[1]!.trigger('click');
        await wrapper.find('[data-save]').trigger('click');

        expect(wrapper.find('[data-test="maintenance-first"]').attributes('disabled')).toBeDefined();
        expect(radios(wrapper, 'options').every((option) => option.attributes('disabled') !== undefined)).toBe(true);
    });

    it('PH-030 says why a next phase cannot be chosen, and Confirm is off', async () => {
        const refused = structuredClone(ending);
        refused.transition!.options[0]!.refusal = 'This phase cannot end before its first week.';
        const wrapper = show({ payload: refused });

        expect(wrapper.find('[data-test="refusal"]').text()).toBe('This phase cannot end before its first week.');
        expect(wrapper.find('[data-save]').attributes('disabled')).toBeDefined();
        await confirm(wrapper);
        expect(write).not.toHaveBeenCalled();
    });

    it.each(['plan_show', 'plan_show.empty'])('PH-030 has nothing to confirm when no phase is ending (%s)', (fixture) => {
        const wrapper = show({ payload: screenFixture<PlanShowOutput>(fixture) });

        expect(wrapper.find('[data-test="no-transition"]').text()).toBe('No phase is ending.');
        expect(wrapper.find('[data-save]').attributes('disabled')).toBeDefined();
    });

    it('PH-007 Cancel leaves without sending anything', async () => {
        const wrapper = show();
        await wrapper.find('[data-cancel]').trigger('click');

        expect(write).not.toHaveBeenCalled();
        expect(replace).toHaveBeenCalledWith('/train');
    });

    it('CAP-002 shows a skeleton while plan_show loads', () => {
        vi.mocked(read).mockReturnValue(new Promise(() => {}));
        const wrapper = show({});

        expect(read).toHaveBeenCalledWith('plan_show', {}, expect.any(Function), expect.any(Function));
        expect(wrapper.find('[data-test="skeleton"]').exists()).toBe(true);
        expect(wrapper.find('[data-save]').attributes('disabled')).toBeDefined();
    });

    it('CAP-002 says the plan could not load and reads again on Try again', async () => {
        vi.mocked(read).mockRejectedValueOnce(new CapabilityError('plan_show', 500, {}));
        const wrapper = show({});
        await flushPromises();

        expect(wrapper.text()).toContain('Your plan could not be loaded.');
        await wrapper.find('[data-test="retry"]').trigger('click');
        await flushPromises();
        expect(rows(wrapper, 'summary')[0]).toBe('Length7 weeks');
    });

    it('CAP-012 shows the server\'s words when the transition is refused, and stays open', async () => {
        vi.mocked(write).mockRejectedValueOnce(new CapabilityError('phase_transition_complete', 422, { error: { message: 'The next phase overlaps a planned one.' } }));
        const wrapper = show();
        await confirm(wrapper);

        expect(wrapper.find('[role="dialog"] [role="alert"]').text()).toContain('The next phase overlaps a planned one.');
        expect(replace).not.toHaveBeenCalled();
    });

    it('OFF-008 dims the phase\'s numbers and the end dates, marked waiting to sync, while a write waits', () => {
        const wrapper = show({ payload: ending, pending: true });

        expect(wrapper.findAll('[data-mark]').length).toBeGreaterThanOrEqual(1);
        expect(wrapper.find('[data-test="summary"]').classes()).toContain('opacity-60');
        expect(wrapper.find('[data-test="lengths"]').classes()).toContain('opacity-60');
        expect(wrapper.find('[data-test="schedule"]').classes()).toContain('opacity-60');
        expect(wrapper.find('[data-test="calories"]').classes()).toContain('opacity-60');
        expect(wrapper.find('[data-test="corridor"]').classes()).toContain('opacity-60');
    });

    it('OFF-008 dims nothing when nothing waits to sync', () => {
        const wrapper = show();

        expect(wrapper.find('[data-mark]').exists()).toBe(false);
        expect(wrapper.find('.opacity-60').exists()).toBe(false);
    });
});
