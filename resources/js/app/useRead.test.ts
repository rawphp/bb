import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';
import { CapabilityError, read } from './call';
import { useRead } from './useRead';

vi.mock('./call', async (original) => ({ ...(await original<typeof import('./call')>()), read: vi.fn() }));

const readMock = vi.mocked(read);

function screen(payload?: { n: number }) {
    let api!: ReturnType<typeof useRead<{ n: number }>>;
    const wrapper = mount(
        defineComponent(() => {
            api = useRead('probe_show', payload);
            return () => h('p', api.failure.value ?? String(api.data.value?.n ?? 'loading'));
        }),
    );
    return { wrapper, api: () => api };
}

describe('useRead', () => {
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

    it('OFF-003 drops a refresh from an older load() that lands after a newer one', async () => {
        let staleRefresh: (data: { n: number }) => void = () => {};
        let staleFail: (error: CapabilityError) => void = () => {};
        readMock
            .mockImplementationOnce(async (_name, _input, onUpdate, onError) => {
                staleRefresh = onUpdate as typeof staleRefresh;
                staleFail = onError as typeof staleFail;
                return { n: 1 };
            })
            .mockResolvedValueOnce({ n: 2 });
        const { wrapper, api } = screen();
        await flushPromises();

        // A write happened; the screen reads again and gets the newer data.
        await api().load();
        await flushPromises();
        staleRefresh({ n: 1 });
        staleFail(new CapabilityError('probe_show', 404, null));
        await flushPromises();

        expect(wrapper.text()).toBe('2');
    });
});
