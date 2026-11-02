import { flushPromises, mount, RouterLinkStub } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { CapabilityError, read } from '@/app/call';
import type { TrainShowOutput } from '@/contracts/train_show';
import { screenFixture } from '@/testing/schemas';
import CurrentMesocycleView from './CurrentMesocycleView.vue';
import MesocyclesView from './MesocyclesView.vue';
import MesocycleView from './MesocycleView.vue';

vi.mock('@/app/call', async (original) => ({ ...(await original<typeof import('@/app/call')>()), read: vi.fn(), write: vi.fn() }));
const push = vi.fn();
vi.mock('vue-router', async (original) => ({ ...(await original<typeof import('vue-router')>()), useRouter: () => ({ push }) }));

const readMock = vi.mocked(read);
const train = screenFixture<TrainShowOutput>('train_show');
const empty = screenFixture<TrainShowOutput>('train_show.empty');
const global = { stubs: { RouterLink: RouterLinkStub, teleport: true } };

describe('MesocyclesView', () => {
    beforeEach(() => {
        readMock.mockReset();
        push.mockReset();
    });

    it('MESO-001 lists current, then planned, then past mesocycles with dates, length and phase', () => {
        const wrapper = mount(MesocyclesView, { props: { payload: train }, global });

        expect(wrapper.findAll('h2').map((h) => h.text())).toEqual(['Unfinished', 'Current', 'Planned', 'Past']);
        const rows = wrapper.findAll('[data-test="row"]');
        expect(rows[0]!.text()).toContain('Hypertrophy Block');
        expect(rows[0]!.text()).toContain('7 Sep – 11 Oct 2026');
        expect(rows[0]!.text()).toContain('5 weeks · Muscle gain');
        expect(wrapper.findAllComponents(RouterLinkStub).map((l) => l.props('to'))).toContain('/train/mesocycles/01J7ZK2C4D6E8F0G1H3J5K7M9N');
        expect(readMock).not.toHaveBeenCalled();
    });

    it('MESO-002 offers the four ways to build one, Copy turned off with nothing to copy', async () => {
        const wrapper = mount(MesocyclesView, { props: { payload: empty }, global });
        await wrapper.find('[data-test="menu"]').trigger('click');

        const items = wrapper.findAll('[role="menuitem"]');
        expect(items.map((i) => i.text())).toEqual(['Template', 'Copy previous mesocycle', 'Meso Builder', 'Build from scratch']);
        expect(wrapper.find('[data-test="item-copy_previous"]').attributes('disabled')).toBeDefined();

        await wrapper.find('[data-test="item-builder"]').trigger('click');
        await flushPromises();
        expect(push).toHaveBeenCalledWith({ path: '/train/mesocycles/build', query: { route: 'builder' } });
    });

    it('MESO-065 shows the priority reset notice on Copy and passes the mesocycle to copy', async () => {
        const warning = screenFixture<TrainShowOutput>('train_show.warning');
        const wrapper = mount(MesocyclesView, { props: { payload: warning }, global });
        await wrapper.find('[data-test="menu"]').trigger('click');

        expect(wrapper.find('[data-test="item-copy_previous"]').text()).toContain('Priorities reset for Fat loss. Your old priorities can be kept.');
        await wrapper.find('[data-test="item-copy_previous"]').trigger('click');
        await flushPromises();
        expect(push).toHaveBeenCalledWith({ path: '/train/mesocycles/build', query: { route: 'copy_previous', copy: '01JB6Q3V8T2K9WZ4M5N7P1R3S5' } });
    });

    it('MESO-007 keeps the draft and warns that a new build replaces it', async () => {
        const wrapper = mount(MesocyclesView, { props: { payload: train }, global });

        expect(wrapper.find('[data-test="draft"]').text()).toContain('Saved as a draft. Tap to continue building.');
        expect(wrapper.findComponent(RouterLinkStub).props('to')).toEqual({ path: '/train/mesocycles/build', query: { draft: '01JBA7X1Y3Z5A7B9C1D3E5F7G9' } });

        await wrapper.find('[data-test="menu"]').trigger('click');
        await wrapper.find('[data-test="item-template"]').trigger('click');
        await flushPromises();
        expect(wrapper.text()).toContain('Replace your draft?');
        expect(wrapper.text()).toContain('You have an unfinished mesocycle. Starting a new one replaces it.');
        expect(push).not.toHaveBeenCalled();

        await wrapper.find('[data-cancel]').trigger('click');
        expect(wrapper.text()).not.toContain('Replace your draft?');

        await wrapper.find('[data-test="menu"]').trigger('click');
        await wrapper.find('[data-test="item-template"]').trigger('click');
        await flushPromises();
        expect(wrapper.find('[data-save]').text()).toBe('Replace draft');
        await wrapper.find('[data-save]').trigger('click');
        expect(push).toHaveBeenCalledWith({ path: '/train/mesocycles/build', query: { route: 'template' } });
    });

    it('MESO-007 reads train_show again before choosing, so a draft made since still gets the question', async () => {
        readMock.mockResolvedValueOnce(empty).mockResolvedValueOnce({ ...empty, draft: train.draft });
        const wrapper = mount(MesocyclesView, { global });
        await flushPromises();
        expect(wrapper.find('[data-test="draft"]').exists()).toBe(false);

        await wrapper.find('[data-test="menu"]').trigger('click');
        await wrapper.find('[data-test="item-template"]').trigger('click');
        await flushPromises();

        expect(readMock).toHaveBeenCalledTimes(2);
        expect(wrapper.text()).toContain('Replace your draft?');
        expect(push).not.toHaveBeenCalled();
    });

    it('OFF-003 shows the fresh train_show over the cached copy, and stops showing the copy when the refresh says signed out', async () => {
        let refresh: (data: TrainShowOutput) => void = () => {};
        let failRefresh: (error: CapabilityError) => void = () => {};
        readMock.mockImplementationOnce(async (_name, _input, onUpdate, onError) => {
            refresh = onUpdate as typeof refresh;
            failRefresh = onError as typeof failRefresh;
            return empty;
        });
        const wrapper = mount(MesocyclesView, { global });
        await flushPromises();
        expect(wrapper.text()).toContain('No mesocycles yet.');

        refresh(train);
        await flushPromises();
        expect(wrapper.findAll('[data-test="row"]')).toHaveLength(3);

        failRefresh(new CapabilityError('train_show', 401, null));
        await flushPromises();
        expect(wrapper.findAll('[data-test="row"]')).toHaveLength(0);
        expect(wrapper.text()).toContain('You are signed out.');
    });

    it('MESO-001 says how to start when there are no mesocycles (train_show.empty)', () => {
        const wrapper = mount(MesocyclesView, { props: { payload: empty }, global });

        expect(wrapper.text()).toContain('No mesocycles yet. Tap + to build one.');
    });

    it('CAP-002 reads train_show with a skeleton, and says when it failed', async () => {
        readMock.mockRejectedValueOnce(new CapabilityError('train_show', 500, null)).mockResolvedValueOnce(train);
        const wrapper = mount(MesocyclesView, { global });
        expect(wrapper.find('[data-test="skeleton"]').exists()).toBe(true);
        await flushPromises();

        expect(wrapper.text()).toContain('Your mesocycles could not be loaded.');
        await wrapper.find('[data-test="retry"]').trigger('click');
        await flushPromises();
        expect(wrapper.findAll('[data-test="row"]')).toHaveLength(3);
    });

    it('CAP-004 says when the user is signed out', async () => {
        readMock.mockRejectedValueOnce(new CapabilityError('train_show', 401, null));
        const wrapper = mount(MesocyclesView, { global });
        await flushPromises();

        expect(wrapper.text()).toContain('You are signed out.');
    });
});

describe('CurrentMesocycleView', () => {
    beforeEach(() => readMock.mockReset());

    it('MESO-040 shows the running mesocycle\'s view', async () => {
        readMock.mockResolvedValue(screenFixture('mesocycle_show'));
        const wrapper = mount(CurrentMesocycleView, { props: { payload: train }, global });
        await flushPromises();

        expect(wrapper.findComponent(MesocycleView).props('mesocycleId')).toBe('01JB6Q3V8T2K9WZ4M5N7P1R3S5');
        expect(readMock).toHaveBeenCalledWith('mesocycle_show', { mesocycle_id: '01JB6Q3V8T2K9WZ4M5N7P1R3S5' }, expect.any(Function), expect.any(Function));
        expect(wrapper.text()).toContain('Week 4');
    });

    it('MESO-040 says no mesocycle is running and links to all of them (train_show.empty)', () => {
        const wrapper = mount(CurrentMesocycleView, { props: { payload: empty }, global });

        expect(wrapper.text()).toContain('No mesocycle is running.');
        expect(wrapper.findComponent(RouterLinkStub).props('to')).toBe('/train/mesocycles');
    });

    it('CAP-002 shows a skeleton while train_show loads, then why it failed', async () => {
        readMock.mockRejectedValueOnce(new CapabilityError('train_show', 500, null));
        const wrapper = mount(CurrentMesocycleView, { global });
        expect(wrapper.find('[data-test="skeleton"]').exists()).toBe(true);
        await flushPromises();

        expect(wrapper.text()).toContain('Your mesocycles could not be loaded.');
    });

    it('CAP-004 says when the user is signed out', async () => {
        readMock.mockRejectedValueOnce(new CapabilityError('train_show', 401, null));
        const wrapper = mount(CurrentMesocycleView, { global });
        await flushPromises();

        expect(wrapper.text()).toContain('You are signed out.');
    });
});
