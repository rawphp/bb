import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { CapabilityError, read, readFresh, write } from '@/app/call';
import AddPhaseView from './AddPhaseView.vue';
import { plan, sent } from './planTesting';

vi.mock('@/app/call', async (original) => ({ ...(await original<typeof import('@/app/call')>()), read: vi.fn(), readFresh: vi.fn(), write: vi.fn() }));
const replace = vi.fn();
vi.mock('vue-router', async (original) => ({ ...(await original<typeof import('vue-router')>()), useRouter: () => ({ replace }) }));

const current = plan();

function show(props: Record<string, unknown> = { payload: current }) {
    return mount(AddPhaseView, { props });
}

const radios = (wrapper: ReturnType<typeof show>, test: string) => wrapper.findAll(`[data-test="${test}"] [role="radio"]`);
const texts = (wrapper: ReturnType<typeof show>, test: string) => wrapper.findAll(`[data-test="${test}"] li`).map((row) => row.text());

async function add(wrapper: ReturnType<typeof show>): Promise<void> {
    await wrapper.find('[data-test="add"]').trigger('click');
    await flushPromises();
}

describe('AddPhaseView', () => {
    beforeEach(() => {
        vi.mocked(read).mockReset().mockResolvedValue(current);
        vi.mocked(readFresh).mockReset().mockResolvedValue(current);
        vi.mocked(write).mockReset().mockResolvedValue({});
        replace.mockReset();
    });

    it('PLAN-006 offers each phase type with the server\'s default picked and its recommendations marked', () => {
        const wrapper = show();

        const types = radios(wrapper, 'types');
        expect(types.map((t) => t.attributes('aria-label'))).toEqual(['Fat loss, recommended', 'Muscle gain, recommended', 'Maintenance', 'Mini-cut']);
        expect(types[0]!.attributes('aria-checked')).toBe('true');
        expect(wrapper.find('[data-test="starts"]').text()).toBe('Starts 21 Dec');
        expect(read).not.toHaveBeenCalled();
    });

    it('PH-045 opens on the type and length a suggestion links to', () => {
        const wrapper = show({ payload: current, type: 'maintenance', weeks: '4' });

        expect(radios(wrapper, 'types').find((t) => t.attributes('aria-checked') === 'true')!.attributes('aria-label')).toBe('Maintenance');
        expect(radios(wrapper, 'lengths').find((l) => l.attributes('aria-checked') === 'true')!.text()).toContain('4 weeks');

        const longer = show({ payload: current, type: 'fatLoss', weeks: '15' });
        expect(radios(longer, 'lengths').find((l) => l.attributes('aria-checked') === 'true')!.text()).toContain('15 weeks');
    });

    it('PLAN-006 ignores a linked type or length the server does not offer', () => {
        const wrapper = show({ payload: current, type: 'bulk', weeks: '99' });

        expect(radios(wrapper, 'types')[0]!.attributes('aria-checked')).toBe('true');
        expect(radios(wrapper, 'lengths').find((l) => l.attributes('aria-checked') === 'true')!.text()).toContain('10 weeks');
    });

    it('PLAN-006 starts from the type the server picks (plan_show.warning)', () => {
        const wrapper = show({ payload: plan('warning') });

        expect(radios(wrapper, 'types')[1]!.attributes('aria-checked')).toBe('true');
        expect(radios(wrapper, 'lengths').map((l) => l.text())).toEqual(['10 weeksEnds 28 Feb', '16 weeksEnds 11 Apr']);
    });

    it('PH-003 offers the server\'s lengths and rates, each with its end date and warnings', async () => {
        const wrapper = show();

        expect(radios(wrapper, 'lengths').map((l) => l.text())).toEqual(['4 weeksEnds 17 Jan', '10 weeksEnds 28 Feb', '15 weeksEnds 4 Apr']);
        expect(radios(wrapper, 'lengths')[1]!.attributes('aria-checked')).toBe('true');
        expect(radios(wrapper, 'rates').map((r) => r.text())).toEqual(['Highest successLose 0.75% a week', 'Slow & steadyLose 0.5% a week']);
        expect(wrapper.find('[data-test="warnings"]').exists()).toBe(false);

        await radios(wrapper, 'lengths')[2]!.trigger('click');
        expect(texts(wrapper, 'warnings')).toEqual([
            'Fat loss over 12 weeks is a long diet. Consider a shorter phase.',
            'This phase plans to lose more than 10% of your starting bodyweight.',
        ]);
    });

    it('PLAN-020 shows the mesocycles that fill the length picked, and where training starts', async () => {
        const wrapper = show();

        expect(texts(wrapper, 'mesocycles')).toEqual(['Mesocycle 15 weeks · 21 Dec – 24 Jan', 'Mesocycle 25 weeks · 25 Jan – 28 Feb']);
        expect(wrapper.text()).toContain('Each mesocycle ends on a deload week.');
        expect(texts(wrapper, 'training')[0]).toBe('All muscles Emphasize');
    });

    it('PH-005 lets Maintenance run with no end, one mesocycle at a time', async () => {
        const wrapper = show();
        await radios(wrapper, 'types')[2]!.trigger('click');
        await radios(wrapper, 'lengths')[1]!.trigger('click');

        expect(wrapper.text()).toContain('A phase with no end gets one mesocycle at a time.');
        expect(wrapper.find('[data-test="rate"]').text()).toBe('HoldHold weight');
        await add(wrapper);
        expect(sent()[0]![1]).toMatchObject({ type: 'maintenance', weeks: null, rate_percent_per_week: 0 });
    });

    it('PLAN-006 adds the phase with a valid phase_add, then leaves', async () => {
        const wrapper = show();
        await radios(wrapper, 'rates')[1]!.trigger('click');
        await add(wrapper);

        const [[name, input]] = sent() as [[string, Record<string, unknown>]];
        expect(name).toBe('phase_add');
        expect(input).toMatchObject({ type: 'fatLoss', weeks: 10, rate_percent_per_week: -0.5 });
        expect(input.phase_id).toMatch(/^[0-9A-HJKMNP-TV-Z]{26}$/);
        expect(replace).toHaveBeenCalledWith('/train');
    });

    it('PLAN-006 a new type starts from the server\'s length and rate for it, not the last type\'s picks', async () => {
        const wrapper = show();
        await radios(wrapper, 'lengths')[2]!.trigger('click');
        await radios(wrapper, 'rates')[1]!.trigger('click');
        await radios(wrapper, 'types')[1]!.trigger('click');

        expect(radios(wrapper, 'lengths')[0]!.attributes('aria-checked')).toBe('true');
        await add(wrapper);
        expect(sent()[0]![1]).toMatchObject({ type: 'muscleGain', weeks: 10, rate_percent_per_week: 0.375 });
    });

    it('PLAN-006 starts from the rate the server marks, wherever it is in the list', async () => {
        const moved = structuredClone(current);
        const rates = moved.add_phase.types[0]!.rates;
        rates[0]!.is_selected = false;
        rates[1]!.is_selected = true;
        const wrapper = show({ payload: moved });

        expect(radios(wrapper, 'rates')[1]!.attributes('aria-checked')).toBe('true');
        await add(wrapper);
        expect(sent()[0]![1]).toMatchObject({ weeks: 10, rate_percent_per_week: -0.5 });
    });

    it('CAP-019 sends the same phase_id again after phase_add got no answer', async () => {
        vi.mocked(write).mockRejectedValueOnce(new CapabilityError('phase_add', 0, { reason: 'network' }));
        const wrapper = show();
        await add(wrapper);
        await add(wrapper);

        const ids = sent().map(([, input]) => input.phase_id);
        expect(ids).toHaveLength(2);
        expect(ids[1]).toBe(ids[0]);
    });

    it('PLAN-006 says why no phase can be added, and Add phase is off', () => {
        const refused = structuredClone(current);
        refused.add_phase.refusal = 'Only the current phase and the next one can be scheduled in detail.';
        const wrapper = show({ payload: refused });

        expect(wrapper.find('[data-test="refusal"]').text()).toBe('Only the current phase and the next one can be scheduled in detail.');
        expect(wrapper.find('[data-test="add"]').attributes('disabled')).toBeDefined();
    });

    it('PLAN-021 lists the fixes for a phase that ends part-way through a mesocycle', () => {
        const midway = structuredClone(current);
        midway.add_phase.types[0]!.lengths[1]!.fixes = [{ fix: 'move_end_to_deload', text: 'End after the nearest deload week' }];
        const wrapper = show({ payload: midway });

        expect(wrapper.text()).toContain('This ends part-way through a mesocycle.');
        expect(texts(wrapper, 'fixes')).toEqual(['End after the nearest deload week']);
    });

    it('PLAN-006 says the plan is not ready when the server offers no type (plan_show.empty)', () => {
        const empty = plan('empty');
        empty.add_phase.types = [];
        const wrapper = show({ payload: empty });

        expect(wrapper.find('[data-test="not-ready"]').text()).toBe('Your plan is not ready yet.');
    });

    it('PLAN-006 renders the empty plan\'s offer (plan_show.empty)', () => {
        const wrapper = show({ payload: plan('empty') });

        expect(wrapper.find('[data-test="starts"]').text()).toBe('Starts 2 Nov');
        expect(wrapper.find('[data-test="add"]').exists()).toBe(true);
    });

    it('CAP-002 shows a skeleton while plan_show loads', () => {
        vi.mocked(read).mockReturnValue(new Promise(() => {}));
        const wrapper = show({});

        expect(read).toHaveBeenCalledWith('plan_show', {}, expect.any(Function), expect.any(Function));
        expect(wrapper.find('[data-test="skeleton"]').exists()).toBe(true);
    });

    it('CAP-002 says the plan could not load and reads again on Try again', async () => {
        vi.mocked(read).mockRejectedValueOnce(new CapabilityError('plan_show', 500, {}));
        const wrapper = show({});
        await flushPromises();

        expect(wrapper.text()).toContain('Your plan could not be loaded.');
        await wrapper.find('[data-test="retry"]').trigger('click');
        await flushPromises();
        expect(wrapper.find('[data-test="add"]').exists()).toBe(true);
    });

    it('CAP-012 shows the server\'s words when the phase is refused, and stays', async () => {
        vi.mocked(write).mockRejectedValueOnce(new CapabilityError('phase_add', 422, { error: { message: 'The plan already has a next phase.' } }));
        const wrapper = show();
        await add(wrapper);

        expect(wrapper.find('[data-test="error"]').text()).toContain('The plan already has a next phase.');
        expect(replace).not.toHaveBeenCalled();
    });

    it('OFF-008 dims the server\'s dates, marked waiting to sync, while a write waits', () => {
        const wrapper = show({ payload: current, pending: true });

        expect(wrapper.find('[data-test="starts"] [data-mark]').exists()).toBe(true);
        expect(wrapper.find('[data-test="starts"] [data-value]').classes()).toContain('opacity-60');
        expect(wrapper.find('[data-test="lengths"]').classes()).toContain('opacity-60');
        expect(wrapper.find('[data-test="mesocycles"] li span.opacity-60').exists()).toBe(true);
    });

    it('OFF-008 dims nothing when nothing waits to sync', () => {
        const wrapper = show();

        expect(wrapper.find('[data-mark]').exists()).toBe(false);
        expect(wrapper.find('.opacity-60').exists()).toBe(false);
    });
});
