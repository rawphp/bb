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
});
