import { flushPromises, mount, RouterLinkStub } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { CapabilityError, read } from '@/app/call';
import type { TrainShowOutput } from '@/contracts/train_show';
import { screenFixture } from '@/testing/schemas';
import TrainView from './TrainView.vue';

vi.mock('@/app/call', async (original) => ({ ...(await original<typeof import('@/app/call')>()), read: vi.fn() }));

const readMock = vi.mocked(read);
const global = { stubs: { RouterLink: RouterLinkStub } };

function links(wrapper: ReturnType<typeof mount>): unknown[] {
    return wrapper.findAllComponents(RouterLinkStub).map((l) => l.props('to'));
}

describe('TrainView', () => {
    beforeEach(() => readMock.mockReset());

    it('NAV-001 lists the plan, the current mesocycle, Mesocycles, Templates and Exercises', () => {
        const wrapper = mount(TrainView, { props: { payload: screenFixture<TrainShowOutput>('train_show') }, global });

        expect(wrapper.find('h1').text()).toBe('Train');
        expect(links(wrapper)).toEqual(['/plan', '/train/current', '/train/mesocycles', '/train/templates', '/exercises']);
        expect(wrapper.text()).not.toContain("Today's session");
        expect(readMock).not.toHaveBeenCalled();
    });

    it('NAV-002 opens today\'s session from the Train tab (train_show.warning)', () => {
        const wrapper = mount(TrainView, { props: { payload: screenFixture<TrainShowOutput>('train_show.warning') }, global });

        const session = wrapper.find('[data-test="todays-session"]');
        expect(session.text()).toContain('Week 4 · Day 2 · Lower A');
        expect(links(wrapper)).toContain('/workouts/01JB6Q3V8T2K9WZ4M5N7P1R3S5/4/2');
    });

    it('NAV-001 keeps its links with no mesocycle (train_show.empty)', () => {
        const wrapper = mount(TrainView, { props: { payload: screenFixture<TrainShowOutput>('train_show.empty') }, global });

        expect(links(wrapper)).toHaveLength(5);
    });

    it('MESO-008 shows the current mesocycle as one card that opens it, with its week, phase and dates', () => {
        const wrapper = mount(TrainView, { props: { payload: screenFixture<TrainShowOutput>('train_show') }, global });

        const card = wrapper.find('[data-test="current-mesocycle"]');
        expect(card.text()).toContain('Hypertrophy Block');
        expect(card.text()).toContain('Week 5 of 5 · Deload');
        expect(card.text()).toContain('Muscle gain · 7 Sep – 11 Oct 2026');
        expect(card.findComponent(RouterLinkStub).props('to')).toBe('/train/current');
        expect(links(wrapper).filter((to) => to === '/train/current')).toHaveLength(1);
    });

    it('MESO-008 draws the server\'s week track, one mark per week, the deload labelled', () => {
        const payload = screenFixture<TrainShowOutput>('train_show');
        payload.current!.week_track = [
            { number: 1, state: 'past', deload: false },
            { number: 2, state: 'current', deload: false },
            { number: 3, state: 'upcoming', deload: false },
            { number: 4, state: 'skipped', deload: true },
        ];
        payload.current!.week_text = 'Week 2 of 4';
        const wrapper = mount(TrainView, { props: { payload }, global });

        const track = wrapper.find('[data-test="week-track"]');
        expect(track.attributes('role')).toBe('img');
        expect(track.attributes('aria-label')).toBe('Week 2 of 4');
        expect(track.findAll('[data-state]').map((mark) => mark.attributes('data-state'))).toEqual(['past', 'current', 'upcoming', 'skipped']);
        expect(wrapper.findAll('[data-test="week-labels"] span').map((label) => label.text())).toEqual(['1', '2', '3', 'Deload']);
    });

    it('MESO-008 NAV-002 puts today\'s session on the card with an Open button', () => {
        const wrapper = mount(TrainView, { props: { payload: screenFixture<TrainShowOutput>('train_show.warning') }, global });

        const card = wrapper.find('[data-test="current-mesocycle"]');
        const session = card.find('[data-test="todays-session"]');
        expect(session.text()).toContain('Today');
        expect(session.text()).toContain('Open');
        expect(session.findComponent(RouterLinkStub).props('to')).toBe('/workouts/01JB6Q3V8T2K9WZ4M5N7P1R3S5/4/2');
    });

    it('MESO-008 MESO-060 says when the next mesocycle starts, with an active rest\'s pill', () => {
        const payload = screenFixture<TrainShowOutput>('train_show');
        const wrapper = mount(TrainView, { props: { payload }, global });

        const next = wrapper.find('[data-test="next-mesocycle"]');
        expect(next.text()).toContain('Next mesocycle');
        expect(next.text()).toContain('Starts Monday 12 Oct');
        expect(next.text()).toContain('Training has gone flat.');
    });

    it('MESO-008 keeps the plain links with no mesocycle running (train_show.empty)', () => {
        const wrapper = mount(TrainView, { props: { payload: screenFixture<TrainShowOutput>('train_show.empty') }, global });

        expect(wrapper.find('[data-test="current-mesocycle"]').exists()).toBe(false);
        expect(links(wrapper)).toContain('/train/current');
    });

    it('CAP-002 reads train_show with a skeleton in place of today\'s session', async () => {
        readMock.mockResolvedValueOnce(screenFixture('train_show.warning'));
        const wrapper = mount(TrainView, { global });
        expect(wrapper.find('[data-test="skeleton"]').exists()).toBe(true);
        expect(links(wrapper)).toHaveLength(5);

        await flushPromises();

        expect(readMock).toHaveBeenCalledWith('train_show', {}, expect.any(Function), expect.any(Function));
        expect(wrapper.text()).toContain('Week 4 · Day 2 · Lower A');
    });

    it('CAP-004 says when today\'s session could not load', async () => {
        readMock.mockRejectedValueOnce(new CapabilityError('train_show', 500, null));
        const wrapper = mount(TrainView, { global });
        await flushPromises();

        expect(wrapper.text()).toContain("Today's session could not be loaded.");
    });

    it('CAP-004 says when the user is signed out', async () => {
        readMock.mockRejectedValueOnce(new CapabilityError('train_show', 401, null));
        const wrapper = mount(TrainView, { global });
        await flushPromises();

        expect(wrapper.text()).toContain('You are signed out.');
    });
});
