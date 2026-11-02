import { flushPromises, mount, RouterLinkStub } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { ProgressShowOutput } from '@/contracts/progress_show';
import content from '../../../../tests/fixtures/screens/progress_show.json';
import empty from '../../../../tests/fixtures/screens/progress_show.empty.json';
import scopeEmpty from '../../../../tests/fixtures/screens/progress_show.scope_empty.json';
import ProgressScreen from './ProgressScreen.vue';
import { sharePng, toPng } from './share';

vi.mock('./share', () => ({ toPng: vi.fn(), sharePng: vi.fn() }));

const full = content as ProgressShowOutput;

function render(props: Record<string, unknown> = { payload: full }) {
    return mount(ProgressScreen, { props, attachTo: document.body, global: { stubs: { RouterLink: RouterLinkStub } } });
}

describe('ProgressScreen', () => {
    beforeEach(() => {
        vi.mocked(toPng).mockReset();
        vi.mocked(sharePng).mockReset();
    });
    afterEach(() => {
        document.body.innerHTML = '';
    });

    it('ANA-001 shows the weight chart with the scope picker, labelled by the server', () => {
        const wrapper = render();

        expect(wrapper.get('h1').text()).toBe('Progress');
        const scopes = wrapper.findAll('[data-scope]');
        expect(scopes.map((s) => s.text())).toEqual(['Phase', 'Whole plan']);
        expect(scopes[0]!.attributes('aria-pressed')).toBe('true');
        expect(scopes[1]!.attributes('aria-pressed')).toBe('false');
        const chart = wrapper.get('svg[role="img"]');
        expect(chart.attributes('aria-label')).toBe('Weight chart. Trend weight from 83.2 kg to 84.3 kg.');
        expect(chart.findAll('[data-weight]')).toHaveLength(full.chart!.weights.length);
        expect(chart.find('[data-trend]').exists()).toBe(true);
        expect(chart.find('[data-goal-range]').exists()).toBe(true);
        expect(chart.text()).toContain('Daily weight');
        expect(chart.text()).toContain('Goal range');
    });

    it('ANA-001 draws the waist line from the server series (WT-021)', () => {
        const chart = render().get('svg[role="img"]');

        expect(chart.find('[data-waist]').exists()).toBe(true);
        expect(chart.text()).toContain('Waist (cm)');
    });

    it('ANA-001 asks for the other scope when it is picked', async () => {
        const wrapper = render();

        await wrapper.findAll('[data-scope]')[1]!.trigger('click');
        await wrapper.findAll('[data-scope]')[0]!.trigger('click');

        expect(wrapper.emitted('scope')).toEqual([['plan']]);
    });

    it('ANA-002 shows start, change and goal as the server words them', () => {
        const summary = render().get('[data-summary]');

        expect(summary.text()).toContain('Start');
        expect(summary.text()).toContain('83.2 kg');
        expect(summary.text()).toContain('+1.1 kg');
        expect(summary.text()).toContain('86.5 kg');
    });

    it('ANA-003 ANA-011 shows calories against the week target, the week strip and Edit schedule', async () => {
        const wrapper = render();
        const week = wrapper.get('[data-this-week]');

        expect(week.text()).toContain('This week');
        expect(week.text()).toContain('2410/18550');
        expect(week.findAll('[role="group"] button')).toHaveLength(7);
        expect(week.get('[role="group"]').text()).toContain('2650');

        await week.get('[data-edit-schedule]').trigger('click');
        expect(document.body.querySelector('[role="dialog"]')?.textContent).toContain('Edit schedule');
    });

    it('ANA-004 shows the weekly review card when a review is available', async () => {
        const wrapper = render();
        const card = wrapper.get('[data-review-card]');

        expect(card.text()).toContain('Weekly review available');
        await card.get('button').trigger('click');
        expect(document.body.querySelector('[role="dialog"]')?.textContent).toContain('Weekly review');
    });

    it('ANA-005 shows steps against the target range', () => {
        const steps = render().get('[data-steps]');

        expect(steps.text()).toContain('6,240/10,000');
        expect(steps.text()).toContain('Target 8,000 to 10,000');
    });

    it('ANA-006 ANA-008 lists past phases with dates, weight change, rate and their mesocycles', () => {
        const card = render().get('[data-phase-history]');

        expect(card.text()).toContain('Fat loss');
        expect(card.text()).toContain('6 Jul 2026 – 30 Aug 2026');
        expect(card.text()).toContain('-2.8 kg');
        expect(card.text()).toContain('-0.41% per week');
        expect(card.text()).toContain('Mesocycles');
        expect(card.text()).toContain('Cut base');
        expect(card.text()).toContain('4 weeks');
    });

    it('ANA-007 lists past mesocycles with dates, phase and average weekly sets per muscle', () => {
        const cards = render().findAll('[data-mesocycle-history]');

        expect(cards).toHaveLength(2);
        expect(cards[0]!.text()).toContain('Lean gain A');
        expect(cards[0]!.text()).toContain('31 Aug 2026 – 4 Oct 2026');
        expect(cards[0]!.text()).toContain('Muscle gain');
        expect(cards[0]!.text()).toContain('Average sets per week');
        expect(cards[0]!.text()).toContain('Back');
        expect(cards[0]!.text()).toContain('5.0');
    });

    it('ANA-009 links to the exercise history', () => {
        const link = render().getComponent(RouterLinkStub);

        expect(link.props('to')).toBe('/progress/exercises');
        expect(link.text()).toContain('Exercise history');
    });

    it('ANA-001 shows the empty state and quiet history before there are enough weigh-ins', async () => {
        const wrapper = render({ payload: empty });

        expect(wrapper.text()).toContain('Log 4 weigh-ins to see your trend');
        expect(wrapper.find('svg[role="img"]').exists()).toBe(false);
        expect(wrapper.find('[data-scope]').exists()).toBe(false);
        expect(wrapper.find('[data-review-card]').exists()).toBe(false);
        expect(wrapper.find('[data-steps]').exists()).toBe(false);
        expect(wrapper.find('[data-share]').exists()).toBe(false);
        expect(wrapper.text()).toContain('No finished phases yet.');
        expect(wrapper.text()).toContain('No finished mesocycles yet.');

        await wrapper.get('[data-log-weigh-in]').trigger('click');
        expect(document.body.querySelector('[role="dialog"]')?.textContent).toContain('Log a weigh-in');
    });

    it('ANA-001 says why a scope has no chart', () => {
        const wrapper = render({ payload: scopeEmpty });

        expect(wrapper.find('[data-scope]').exists()).toBe(true);
        expect(wrapper.find('svg[role="img"]').exists()).toBe(false);
        expect(wrapper.text()).toContain('No weigh-ins in this phase yet');
    });

    it('PWA-024 shows skeletons while loading', () => {
        const wrapper = render({});

        expect(wrapper.get('[role="status"]').text()).toBe('Loading progress');
        expect(wrapper.findAll('[data-placeholder]').length).toBeGreaterThan(0);
        expect(wrapper.find('[data-summary]').exists()).toBe(false);
    });

    it('CAP-012 says why progress did not load', () => {
        const wrapper = render({ error: 'Progress could not be loaded.' });

        expect(wrapper.get('[role="alert"]').text()).toBe('Progress could not be loaded.');
        expect(wrapper.find('[data-placeholder]').exists()).toBe(false);
    });

    it('OFF-003 says progress is not available offline', () => {
        const wrapper = render({ offline: true });

        expect(wrapper.text()).toContain('Not available offline');
        expect(wrapper.text()).toContain('Progress needs a connection.');
    });

    it('OFF-008 dims the server numbers and says they are waiting to sync', () => {
        const wrapper = render({ payload: full, pending: true });

        expect(wrapper.get('[data-summary]').classes()).toContain('opacity-50');
        expect(wrapper.get('[data-chart-card]').text()).toContain('Waiting to sync');
        expect(wrapper.get('[data-this-week]').text()).toContain('waiting to sync');
        expect(wrapper.get('[data-steps]').text()).toContain('Waiting to sync');
        expect(wrapper.get('[data-phase-history]').text()).toContain('Waiting to sync');
    });

    it('PWA-034 shares the progress card as an image', async () => {
        const png = new Blob(['png'], { type: 'image/png' });
        vi.mocked(toPng).mockResolvedValue(png);
        vi.mocked(sharePng).mockResolvedValue('shared');
        const wrapper = render();

        await wrapper.get('[data-share]').trigger('click');
        await flushPromises();

        const card = vi.mocked(toPng).mock.calls[0]![0];
        expect(card.textContent).toContain('My progress');
        expect(card.textContent).toContain('Muscle gain: 83.2 kg to 84.3 kg (+1.1 kg) over 5 weeks');
        expect(sharePng).toHaveBeenCalledWith(png, full.share);
        expect(wrapper.find('[data-share-card]').exists()).toBe(false);
    });
});
