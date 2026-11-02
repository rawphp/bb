import { flushPromises, mount, RouterLinkStub } from '@vue/test-utils';
import { beforeEach, describe, expect, it, onTestFinished, vi } from 'vitest';
import { CapabilityError, read, readFresh, writeNow } from '@/app/call';
import type { PlanShowOutput } from '@/contracts/plan_show';
import { inputViolations, screenFixture } from '@/testing/schemas';
import PlanView from './PlanView.vue';

vi.mock('@/app/call', async (original) => ({ ...(await original<typeof import('@/app/call')>()), read: vi.fn(), readFresh: vi.fn(), writeNow: vi.fn() }));

const readMock = vi.mocked(read);
const writeMock = vi.mocked(writeNow);
const plan = screenFixture<PlanShowOutput>('plan_show');
const empty = screenFixture<PlanShowOutput>('plan_show.empty');
const warning = screenFixture<PlanShowOutput>('plan_show.warning');
const global = { stubs: { RouterLink: RouterLinkStub, teleport: true } };

function show(payload?: PlanShowOutput, pending = false) {
    return mount(PlanView, { props: { ...(payload ? { payload } : {}), pending }, attachTo: document.body, global });
}

/** Each write sent, as [name, input], checked against that write's schema snapshot. */
function sent(): [string, unknown][] {
    return writeMock.mock.calls.map(([name, input]) => {
        expect(inputViolations(name, input), name).toEqual([]);
        return [name, input];
    });
}

const links = (wrapper: ReturnType<typeof show>) => wrapper.findAllComponents(RouterLinkStub).map((link) => link.props('to'));

beforeEach(() => {
    readMock.mockReset().mockResolvedValue(plan);
    vi.mocked(readFresh).mockReset().mockResolvedValue(plan);
    writeMock.mockReset().mockResolvedValue({});
    document.body.innerHTML = '';
});

describe('PlanView', () => {
    it('PLAN-003 shows phases on top, mesocycles under them, deload weeks and today on one week grid', () => {
        const wrapper = show(plan);

        expect(wrapper.find('h1').text()).toBe('Plan and phases');
        const phases = wrapper.findAll('[data-test="phase"]');
        expect(phases.map((p) => p.findAll('span').map((s) => s.text()))).toEqual([
            ['Fat loss', 'Lose 0.75% a week'],
            ['Maintenance', 'Hold weight'],
        ]);
        expect(phases[1]!.attributes('style')).toContain('left: 360px');
        expect(phases[1]!.attributes('style')).toContain('width: 118px');
        expect(wrapper.findAll('[data-test="mesocycle"]').map((m) => m.text())).toEqual(['Meso 1', 'Meso 2', 'Meso 3']);
        expect(wrapper.findAll('[data-test="deload"]').map((d) => d.attributes('aria-label'))).toEqual([
            'Deload · maintenance calories, 5 – 11 Oct',
            'Deload · maintenance calories, 16 – 22 Nov',
            'Deload · maintenance calories, 14 – 20 Dec',
        ]);
        expect(wrapper.find('[data-test="today"]').attributes('style')).toContain('left: 192.9px');
        expect(wrapper.findAll('[data-test="ruler"] span').map((s) => s.text())).toEqual(['1', '5', '9', '13']);
        expect(readMock).not.toHaveBeenCalled();
    });

    it('PLAN-003 starts the timeline at week 1 while today is in view, else scrolls today into view', () => {
        const width = Object.getOwnPropertyDescriptor(Element.prototype, 'clientWidth')!;
        Object.defineProperty(Element.prototype, 'clientWidth', { configurable: true, get: () => 360 });
        onTestFinished(() => {
            Object.defineProperty(Element.prototype, 'clientWidth', width);
        });
        const scroller = (wrapper: ReturnType<typeof show>) => wrapper.find('[data-test="timeline"]').element.parentElement!;

        expect(scroller(show(plan)).scrollLeft).toBe(0);
        expect(scroller(show({ ...plan, week_count: 30, today_position: 20.5 })).scrollLeft).toBe(540);
    });

    it('PLAN-003 marks the current mesocycle, past phases and the deficit deload', () => {
        const past = { ...plan.phases[0]!, id: 'past', state: 'past' as const };
        const wrapper = show({ ...warning, phases: [past, ...warning.phases] });

        expect(wrapper.findAll('[data-test="mesocycle"]').map((m) => m.attributes('aria-current'))).toEqual([undefined, 'true', undefined]);
        expect(wrapper.find('[data-test="phase"]').attributes('data-state')).toBe('past');
        expect(wrapper.findAll('[data-test="deload"]').map((d) => d.attributes('data-marker'))).toEqual(['deload', 'deficit_deload']);
    });

    it('PLAN-004 PLAN-005 a phase opens its detail and a mesocycle opens the mesocycle', () => {
        const wrapper = show(plan);

        expect(links(wrapper)).toEqual(
            expect.arrayContaining([
                '/plan/phases/01JB8Q2V6N3XKZ4M5P7R9T1W2A',
                '/plan/phases/01JB8Q2V6N3XKZ4M5P7R9T1W2B',
                '/train/mesocycles/01JB8Q3A1C2D3E4F5G6H7J8K9N',
            ]),
        );
    });

    it('PLAN-006 draws later phases as outlines and offers to add a phase or a 24-week plan', () => {
        const outline = { ...plan.phases[1]!, id: 'later', start_week: 16, state: 'outline' as const };
        const wrapper = show({ ...plan, week_count: 20, phases: [...plan.phases, outline] });

        expect(wrapper.findAll('[data-test="phase"]').map((p) => p.attributes('data-state'))).toEqual(['current', 'future', 'outline']);
        expect(wrapper.findAll('[data-test="phase"]')[2]!.attributes('aria-label')).toBe('Maintenance, 4 weeks, Hold weight, outline');
        expect(wrapper.find('[data-test="add-phase"]').text()).toBe('Add a phase');
        expect(wrapper.find('[data-test="prebuilt"]').text()).toBe("RP's 24-week plans");
        expect(links(wrapper)).toEqual(expect.arrayContaining(['/train/plan/add-phase', '/train/plan/prebuilt']));
    });

    it('PH-043 counts down to the event (plan_show.warning)', () => {
        const wrapper = show(warning);

        expect(wrapper.find('[data-test="countdown"]').text()).toBe('52 days to your event');
    });

    it('PH-045 suggests a Maintenance phase and opens Add a phase with it', () => {
        const wrapper = show({ ...plan, maintenance_suggestion: { text: 'No Maintenance in 5 months. Add 4 weeks?', weeks: 4 } });

        expect(wrapper.find('[data-test="suggestion"]').text()).toBe('No Maintenance in 5 months. Add 4 weeks?');
        expect(wrapper.findComponent<typeof RouterLinkStub>('[data-test="suggestion"]').props('to')).toEqual({
            path: '/train/plan/add-phase',
            query: { type: 'maintenance', weeks: '4' },
        });
    });

    it('PLAN-022 opens a deload week with its calories and no switch when it cannot change', async () => {
        const wrapper = show(plan);
        await wrapper.findAll('[data-test="deload"]')[0]!.trigger('click');

        expect(wrapper.text()).toContain('Week 6');
        expect(wrapper.find('[data-test="deload-sheet"]').text()).toContain('Deload · maintenance calories');
        expect(wrapper.find('[data-test="deload-sheet"]').text()).toContain('5 – 11 Oct');
        expect(wrapper.find('input[type="checkbox"]').exists()).toBe(false);

        await wrapper.find('[data-save]').trigger('click');
        expect(wrapper.find('[data-test="deload-sheet"]').exists()).toBe(false);
    });

    it('PLAN-023 warns before turning a diet break off, and sends nothing when the user keeps it', async () => {
        const wrapper = show(plan);
        await wrapper.findAll('[data-test="deload"]')[1]!.trigger('click');
        const toggle = wrapper.find<HTMLInputElement>('input[type="checkbox"]');
        expect(toggle.element.checked).toBe(true);

        await toggle.setValue(false);
        expect(wrapper.find('[data-test="diet-break-warning"]').text()).toContain(plan.diet_break_off_warning);
        expect(writeMock).not.toHaveBeenCalled();

        await wrapper.find('[data-test="keep-diet-break"]').trigger('click');
        expect(wrapper.find('[data-test="diet-break-warning"]').exists()).toBe(false);
        expect(wrapper.find<HTMLInputElement>('input[type="checkbox"]').element.checked).toBe(true);
        expect(writeMock).not.toHaveBeenCalled();
    });

    it('PLAN-023 turns the diet break off once the user confirms, then reads the plan again', async () => {
        const wrapper = show(plan);
        await wrapper.findAll('[data-test="deload"]')[1]!.trigger('click');
        await wrapper.find('input[type="checkbox"]').setValue(false);
        await wrapper.find('[data-test="turn-off-diet-break"]').trigger('click');
        await flushPromises();

        expect(sent()).toEqual([['plan_diet_break_set', { week_start: '2026-11-16', diet_break: false }]]);
        expect(readMock).toHaveBeenCalledWith('plan_show', {}, expect.any(Function), expect.any(Function));
    });

    it('PLAN-023 turns a deficit deload back to maintenance calories at once (plan_show.warning)', async () => {
        const wrapper = show(warning);
        await wrapper.findAll('[data-test="deload"]')[1]!.trigger('click');
        const toggle = wrapper.find<HTMLInputElement>('input[type="checkbox"]');
        expect(toggle.element.checked).toBe(false);

        await toggle.setValue(true);
        await flushPromises();

        expect(sent()).toEqual([['plan_diet_break_set', { week_start: '2026-11-16', diet_break: true }]]);
    });

    it('PLAN-023 keeps the switch at the server\'s state until the server answers', async () => {
        writeMock.mockImplementationOnce(() => new Promise(() => undefined));
        const wrapper = show(warning);
        await wrapper.findAll('[data-test="deload"]')[1]!.trigger('click');
        const toggle = wrapper.find<HTMLInputElement>('input[type="checkbox"]').element;
        toggle.checked = true;
        toggle.dispatchEvent(new Event('change'));
        await flushPromises();

        // The switch the user tapped: the teleport stub may render a new one on each update.
        expect(writeMock).toHaveBeenCalledTimes(1);
        expect(toggle.checked).toBe(false);
        expect(wrapper.find<HTMLInputElement>('input[type="checkbox"]').element.checked).toBe(false);
    });

    it('CAP-012 shows the server\'s refusal in the deload sheet', async () => {
        writeMock.mockRejectedValueOnce(new CapabilityError('plan_diet_break_set', 422, { error: { code: 'validation_failed', message: 'This deload has passed.', violations: [] } }));
        const wrapper = show(warning);
        await wrapper.findAll('[data-test="deload"]')[1]!.trigger('click');
        await wrapper.find('input[type="checkbox"]').setValue(true);
        await flushPromises();

        expect(wrapper.find('[data-test="deload-sheet"]').text()).toContain('This deload has passed.');
        expect(wrapper.find<HTMLInputElement>('input[type="checkbox"]').element.checked).toBe(false);
    });

    it('UI-022 keeps the deload sheet open while its write is in flight', async () => {
        writeMock.mockImplementationOnce(() => new Promise(() => undefined));
        const wrapper = show(warning);
        await wrapper.findAll('[data-test="deload"]')[1]!.trigger('click');
        await wrapper.find('input[type="checkbox"]').setValue(true);
        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
        await wrapper.find('[data-cancel]').trigger('click');
        await wrapper.find('[data-save]').trigger('click');
        await flushPromises();

        expect(wrapper.find('[data-test="deload-sheet"]').exists()).toBe(true);
    });

    it('PLAN-003 says the plan appears once setup is done, and still offers to add a phase (plan_show.empty)', () => {
        const wrapper = show(empty);

        expect(wrapper.text()).toContain('Your plan will appear here once setup is done.');
        expect(wrapper.find('[data-test="phase"]').exists()).toBe(false);
        expect(wrapper.find('[data-test="add-phase"]').exists()).toBe(true);
    });

    it('OFF-008 marks the plan\'s server numbers waiting to sync and dims them', () => {
        const wrapper = show(warning, true);

        expect(wrapper.findAll('[data-mark]')).toHaveLength(1);
        expect(wrapper.find('[data-test="timeline"]').classes()).toContain('opacity-60');
        expect(wrapper.find('[data-value]').text()).toBe('52 days to your event');
    });

    it('OFF-008 marks a plan with no event countdown as waiting to sync too', () => {
        const wrapper = show(plan, true);

        expect(wrapper.findAll('[data-mark]')).toHaveLength(1);
        expect(wrapper.find('[data-test="timeline"]').classes()).toContain('opacity-60');
    });

    it('OFF-008 shows no mark when nothing waits to sync', () => {
        const wrapper = show(warning);

        expect(wrapper.find('[data-mark]').exists()).toBe(false);
        expect(wrapper.find('.opacity-60').exists()).toBe(false);
    });

    it('CAP-002 reads plan_show with a skeleton, then says why it failed and tries again', async () => {
        readMock.mockReset().mockRejectedValueOnce(new CapabilityError('plan_show', 500, null)).mockResolvedValueOnce(plan);
        const wrapper = show();
        expect(wrapper.find('[data-test="skeleton"]').exists()).toBe(true);
        await flushPromises();

        expect(wrapper.text()).toContain('Your plan could not be loaded.');
        await wrapper.find('[data-test="retry"]').trigger('click');
        await flushPromises();
        expect(wrapper.findAll('[data-test="phase"]')).toHaveLength(2);
    });

    it('CAP-004 says when the user is signed out', async () => {
        readMock.mockReset().mockRejectedValueOnce(new CapabilityError('plan_show', 401, null));
        const wrapper = show();
        await flushPromises();

        expect(wrapper.text()).toContain('You are signed out.');
    });
});
