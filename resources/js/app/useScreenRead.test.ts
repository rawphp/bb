import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, ref } from 'vue';
import { CapabilityError, read, readFresh } from './call';
import ScreenFrame from './ScreenFrame.vue';
import { useScreenRead } from './useScreenRead';

vi.mock('./call', async (original) => ({ ...(await original<typeof import('./call')>()), read: vi.fn(), readFresh: vi.fn() }));

const readMock = vi.mocked(read);
const readFreshMock = vi.mocked(readFresh);

function screen(payload?: { n: number }) {
    let api!: ReturnType<typeof useScreenRead<{ n: number }>>;
    const wrapper = mount(
        defineComponent(() => {
            api = useScreenRead('probe_show', payload);
            return () => h('p', api.failure.value ?? String(api.data.value?.n ?? 'loading'));
        }),
    );
    return { wrapper, api: () => api };
}

describe('useScreenRead', () => {
    beforeEach(() => {
        readMock.mockReset();
        readFreshMock.mockReset();
    });

    it('CAP-002 shows a given payload and reads nothing', () => {
        const { wrapper } = screen({ n: 1 });

        expect(wrapper.text()).toBe('1');
        expect(readMock).not.toHaveBeenCalled();
    });

    it('CAP-002 reads the screen on mount', async () => {
        readMock.mockResolvedValueOnce({ n: 2 });
        const { wrapper } = screen();
        expect(wrapper.text()).toBe('loading');

        await flushPromises();

        expect(readMock).toHaveBeenCalledWith('probe_show', {}, expect.any(Function), expect.any(Function));
        expect(wrapper.text()).toBe('2');
    });

    it.each([
        [401, 'signed-out'],
        [500, 'error'],
    ])('CAP-004 names a failed read (%i)', async (status, failure) => {
        readMock.mockRejectedValueOnce(new CapabilityError('probe_show', status, null));
        const { wrapper } = screen();
        await flushPromises();

        expect(wrapper.text()).toBe(failure);
    });

    it('OFF-003 keeps a refresh that lands before the cached copy is handed back', async () => {
        readMock.mockImplementationOnce(async (_name, _input, onUpdate) => {
            (onUpdate as (d: { n: number }) => void)({ n: 9 });
            return { n: 1 };
        });
        const { wrapper } = screen();
        await flushPromises();

        expect(wrapper.text()).toBe('9');
    });

    it('OFF-003 stops showing the cached copy when its refresh is refused', async () => {
        let failRefresh: (error: CapabilityError) => void = () => {};
        readMock.mockImplementationOnce(async (_name, _input, _onUpdate, onError) => {
            failRefresh = onError as typeof failRefresh;
            return { n: 1 };
        });
        const { wrapper } = screen();
        await flushPromises();

        failRefresh(new CapabilityError('probe_show', 401, null));
        await flushPromises();

        expect(wrapper.text()).toBe('signed-out');
    });

    it('CAP-010 reads again on load(), after a failure', async () => {
        readMock.mockRejectedValueOnce(new CapabilityError('probe_show', 500, null)).mockResolvedValueOnce({ n: 3 });
        const { wrapper, api } = screen();
        await flushPromises();

        await api().load();
        await flushPromises();

        expect(wrapper.text()).toBe('3');
    });

    it('CAP-002 reads with the input the screen gives', async () => {
        readMock.mockResolvedValueOnce({ n: 4 });
        mount(defineComponent(() => {
            useScreenRead('probe_show', undefined, () => ({ id: 'a' }));
            return () => h('p');
        }));
        await flushPromises();

        expect(readMock).toHaveBeenCalledWith('probe_show', { id: 'a' }, expect.any(Function), expect.any(Function));
    });

    it('PWA-020 reads again when its screen comes back on top of the stack, but not a fixture', async () => {
        readMock.mockResolvedValueOnce({ n: 1 }).mockResolvedValueOnce({ n: 2 });
        const top = ref(true);
        const Probe = defineComponent((props: { payload?: { n: number } }) => {
            const api = useScreenRead('probe_show', props.payload);
            return () => h('p', String(api.data.value?.n ?? 'loading'));
        }, { props: ['payload'] });
        const wrapper = mount(defineComponent(() => () => h('div', [
            h(ScreenFrame, { top: top.value }, () => h(Probe)),
            h(ScreenFrame, { top: top.value }, () => h(Probe, { payload: { n: 7 } })),
        ])));
        await flushPromises();
        expect(wrapper.text()).toBe('17');

        top.value = false;
        await flushPromises();
        expect(readMock).toHaveBeenCalledTimes(1);

        top.value = true;
        await flushPromises();
        expect(readMock).toHaveBeenCalledTimes(2);
        expect(wrapper.text()).toBe('27');
    });

    it('OFF-003 never lets an older read\'s late answer replace a newer read\'s', async () => {
        let refreshA: (d: { n: number }) => void = () => {};
        let failA: (e: CapabilityError) => void = () => {};
        readMock
            .mockImplementationOnce(async (_name, _input, onUpdate, onError) => {
                refreshA = onUpdate as typeof refreshA;
                failA = onError as typeof failA;
                return { n: 0 };
            })
            .mockResolvedValueOnce({ n: 2 });
        const { wrapper, api } = screen();
        await flushPromises();
        expect(wrapper.text()).toBe('0');

        await api().load();
        await flushPromises();
        expect(wrapper.text()).toBe('2');

        refreshA({ n: 1 });
        await flushPromises();
        expect(wrapper.text()).toBe('2');

        failA(new CapabilityError('probe_show', 401, null));
        await flushPromises();
        expect(wrapper.text()).toBe('2');
    });

    it('OFF-003 drops an older read\'s cached copy that is handed back after a newer read answered', async () => {
        let handBackA: (d: { n: number }) => void = () => {};
        readMock
            .mockImplementationOnce(() => new Promise((resolve) => (handBackA = resolve as typeof handBackA)))
            .mockResolvedValueOnce({ n: 2 });
        const { wrapper, api } = screen();

        await api().load();
        await flushPromises();
        expect(wrapper.text()).toBe('2');

        handBackA({ n: 1 });
        await flushPromises();
        expect(wrapper.text()).toBe('2');
    });

    it('OFF-011 load({ fresh: true }) shows the server\'s answer, never the cached copy', async () => {
        readMock.mockResolvedValueOnce({ n: 1 });
        readFreshMock.mockResolvedValueOnce({ n: 5 });
        const { wrapper, api } = screen();
        await flushPromises();

        await api().load({ fresh: true });
        await flushPromises();

        expect(readFreshMock).toHaveBeenCalledWith('probe_show', {});
        expect(readMock).toHaveBeenCalledTimes(1);
        expect(wrapper.text()).toBe('5');
    });

    it.each([0, 500])('OFF-003 keeps showing the screen when a fresh read gets no answer (%i)', async (status) => {
        readMock.mockResolvedValueOnce({ n: 1 });
        readFreshMock.mockRejectedValueOnce(new CapabilityError('probe_show', status, null));
        const { wrapper, api } = screen();
        await flushPromises();

        await api().load({ fresh: true });
        await flushPromises();

        expect(wrapper.text()).toBe('1');
    });

    it('OFF-003 says a fresh read failed when there was nothing to keep showing', async () => {
        readMock.mockRejectedValueOnce(new CapabilityError('probe_show', 500, null));
        readFreshMock.mockRejectedValueOnce(new CapabilityError('probe_show', 0, { reason: 'network' }));
        const { wrapper, api } = screen();
        await flushPromises();

        await api().load({ fresh: true });
        await flushPromises();

        expect(wrapper.text()).toBe('error');
    });

    it('CAP-004 a plain read that fails stops showing the screen, as read() had no cached copy to give', async () => {
        readMock.mockResolvedValueOnce({ n: 1 }).mockRejectedValueOnce(new CapabilityError('probe_show', 500, null));
        const { wrapper, api } = screen();
        await flushPromises();

        await api().load();
        await flushPromises();

        expect(wrapper.text()).toBe('error');
    });

    it('OFF-003 stops showing the screen when a fresh read is refused', async () => {
        readMock.mockResolvedValueOnce({ n: 1 });
        readFreshMock.mockRejectedValueOnce(new CapabilityError('probe_show', 401, null));
        const { wrapper, api } = screen();
        await flushPromises();

        await api().load({ fresh: true });
        await flushPromises();

        expect(wrapper.text()).toBe('signed-out');
    });
});
