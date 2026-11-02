import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { CapabilityError, read, readFresh, write } from '@/app/call';
import { plan, sent } from './planTesting';
import PrebuiltPlansView from './PrebuiltPlansView.vue';

vi.mock('@/app/call', async (original) => ({ ...(await original<typeof import('@/app/call')>()), read: vi.fn(), readFresh: vi.fn(), write: vi.fn() }));
const replace = vi.fn();
vi.mock('vue-router', async (original) => ({ ...(await original<typeof import('vue-router')>()), useRouter: () => ({ replace }) }));

const current = plan();

function show(props: Record<string, unknown> = { payload: current }) {
    return mount(PrebuiltPlansView, { props });
}

const plans = (wrapper: ReturnType<typeof show>) => wrapper.findAll('[data-test="plan"]');

describe('PrebuiltPlansView', () => {
    beforeEach(() => {
        vi.mocked(read).mockReset().mockResolvedValue(current);
        vi.mocked(readFresh).mockReset().mockResolvedValue(current);
        vi.mocked(write).mockReset().mockResolvedValue({});
        replace.mockReset();
    });

    it('PLAN-030 previews both 24-week plans: phases, rates, mesocycles and active rest', () => {
        const wrapper = show();

        expect(plans(wrapper).map((p) => p.find('h2').text())).toEqual(['Muscle gain plan', 'Fat loss plan']);
        const gain = plans(wrapper)[0]!;
        expect(gain.text()).toContain("24 weeks from RP's 6-month guide · Starts 21 Dec");
        expect(gain.findAll('[data-test="phases"] li').map((li) => li.text())).toEqual([
            'Muscle gain · 17 weeks21 Dec – 18 Apr · Gain 0.5% a week',
            'Maintenance · 2 weeks19 Apr – 2 May · Hold weight',
            'Fat loss · 6 weeks3 May – 13 Jun · Lose 0.75% a week',
        ]);
        const segments = gain.findAll('[data-test="segments"] li');
        expect(segments.map((li) => li.text())).toEqual([
            'Mesocycle 1 · 5 weeks',
            'Mesocycle 2 · 4 weeks',
            'Mesocycle 3 · 4 weeks',
            'Mesocycle 4 · 4 weeks',
            'Active rest · 2 weeks',
            'Mesocycle 5 · 6 weeks',
        ]);
        expect(segments[4]!.attributes('data-kind')).toBe('active_rest');
        expect(plans(wrapper)[1]!.find('[data-test="segments"] li:nth-child(4)').text()).toBe('Active rest · 1 week');
        expect(read).not.toHaveBeenCalled();
    });

    it('PLAN-030 adds a plan with a valid prebuilt_plan_apply, then leaves', async () => {
        const wrapper = show();
        await plans(wrapper)[1]!.find('[data-test="apply"]').trigger('click');
        await flushPromises();

        const [[name, input]] = sent() as [[string, Record<string, unknown>]];
        expect(name).toBe('prebuilt_plan_apply');
        expect(input.plan).toBe('fatLoss');
        expect(input.first_phase_id).toMatch(/^[0-9A-HJKMNP-TV-Z]{26}$/);
        expect(replace).toHaveBeenCalledWith('/train');
    });

    it('PLAN-030 says why a plan cannot be added, in place of its button', () => {
        const refused = structuredClone(current);
        refused.prebuilt_plans[0]!.refusal = 'A 24-week plan needs a plan with an end date.';
        const wrapper = show({ payload: refused });

        expect(plans(wrapper)[0]!.find('[data-test="refusal"]').text()).toBe('A 24-week plan needs a plan with an end date.');
        expect(plans(wrapper)[0]!.find('[data-test="apply"]').exists()).toBe(false);
        expect(plans(wrapper)[1]!.find('[data-test="apply"]').exists()).toBe(true);
    });

    it('PLAN-030 says when no plan can be added (plan_show.empty)', () => {
        const wrapper = show({ payload: plan('empty') });

        expect(plans(wrapper)).toHaveLength(0);
        expect(wrapper.find('[data-test="none"]').text()).toBe('No 24-week plan can be added to your plan right now.');
    });

    it('PLAN-030 renders the same plans beside an ending phase (plan_show.warning)', () => {
        const wrapper = show({ payload: plan('warning') });

        expect(plans(wrapper)).toHaveLength(2);
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
        expect(plans(wrapper)).toHaveLength(2);
    });

    it('CAP-012 shows the server\'s words when a plan is refused, and stays', async () => {
        vi.mocked(write).mockRejectedValueOnce(new CapabilityError('prebuilt_plan_apply', 422, { error: { message: 'Your plan already runs past 24 weeks.' } }));
        const wrapper = show();
        await plans(wrapper)[0]!.find('[data-test="apply"]').trigger('click');
        await flushPromises();

        expect(wrapper.find('[data-test="error"]').text()).toContain('Your plan already runs past 24 weeks.');
        expect(replace).not.toHaveBeenCalled();
    });

    it('OFF-008 dims the server\'s dates, marked waiting to sync, while a write waits', () => {
        const wrapper = show({ payload: current, pending: true });

        expect(wrapper.findAll('[data-mark]')).toHaveLength(2);
        expect(wrapper.find('[data-test="phases"] li p.opacity-60').exists()).toBe(true);
    });

    it('OFF-008 dims nothing when nothing waits to sync', () => {
        const wrapper = show();

        expect(wrapper.find('[data-mark]').exists()).toBe(false);
        expect(wrapper.find('.opacity-60').exists()).toBe(false);
    });
});
