import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, ref } from 'vue';
import { CapabilityError, read } from './call';
import ScreenFrame from './ScreenFrame.vue';
import { useScreenRead } from './useScreenRead';

vi.mock('./call', async (original) => ({ ...(await original<typeof import('./call')>()), read: vi.fn() }));

const readMock = vi.mocked(read);

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
    beforeEach(() => readMock.mockReset());

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
});
