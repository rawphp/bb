import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';
import { CapabilityError, read } from './call';
import type { ReadFailure } from './screenRead';
import { useRead } from './useRead';

vi.mock('./call', async (original) => ({ ...(await original<typeof import('./call')>()), read: vi.fn() }));

const readMock = vi.mocked(read);

// A mounted screen listens for reconnects; one left from an earlier test would read again.
enableAutoUnmount(afterEach);

function label(failure: ReadFailure | null): string | null {
    if (failure === null) return null;
    return failure.offline ? 'offline' : failure.signedOut ? 'signed-out' : 'error';
}

function screen(payload?: { n: number }) {
    let api!: ReturnType<typeof useRead<{ n: number }>>;
    const wrapper = mount(
        defineComponent(() => {
            api = useRead('probe_show', { id: 7 }, 'The probe could not be loaded.', payload);
            return () => h('p', label(api.failure.value) ?? String(api.data.value?.n ?? 'loading'));
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

        expect(readMock).toHaveBeenCalledWith('probe_show', { id: 7 }, expect.any(Function), expect.any(Function));
        expect(wrapper.text()).toBe('2');
    });

    it.each([
        [new CapabilityError('probe_show', 401, null), 'signed-out'],
        [new CapabilityError('probe_show', 419, null), 'signed-out'],
        [new CapabilityError('probe_show', 0, null), 'offline'],
        [new CapabilityError('probe_show', 404, null), 'error'],
        [new CapabilityError('probe_show', 500, null), 'error'],
        [new Error('boom'), 'error'],
    ])('CAP-004 names a failed read (%s)', async (error, failure) => {
        readMock.mockRejectedValueOnce(error);
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

    it('OFF-003 a refusal that lands before read() returns keeps the cached copy away', async () => {
        readMock.mockImplementationOnce(async (_name, _input, _onUpdate, onError) => {
            (onError as (error: CapabilityError) => void)(new CapabilityError('probe_show', 404, null));
            return { n: 1 };
        });
        const { wrapper } = screen();
        await flushPromises();

        expect(wrapper.text()).toBe('error');
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

    it('OFF-004 reads again when the device reconnects after an offline failure', async () => {
        readMock.mockRejectedValueOnce(new CapabilityError('probe_show', 0, { reason: 'network' })).mockResolvedValueOnce({ n: 4 });
        const { wrapper } = screen();
        await flushPromises();
        expect(wrapper.text()).toBe('offline');

        window.dispatchEvent(new Event('online'));
        await flushPromises();

        expect(readMock).toHaveBeenCalledTimes(2);
        expect(wrapper.text()).toBe('4');
    });

    it('OFF-004 a reconnect reads nothing while the screen has data, or after it is gone', async () => {
        readMock.mockRejectedValueOnce(new CapabilityError('probe_show', 0, { reason: 'network' }));
        const shown = screen({ n: 1 });
        window.dispatchEvent(new Event('online'));
        const failed = screen();
        await flushPromises();
        failed.wrapper.unmount();

        window.dispatchEvent(new Event('online'));
        await flushPromises();

        expect(shown.wrapper.text()).toBe('1');
        expect(readMock).toHaveBeenCalledTimes(1);
    });

    it('OFF-003 drops the cached copy an older load() hands back after a newer load() answered', async () => {
        let handBack: (data: { n: number }) => void = () => {};
        readMock.mockImplementationOnce(() => new Promise((resolve) => (handBack = resolve as typeof handBack))).mockResolvedValueOnce({ n: 2 });
        const { wrapper, api } = screen();

        await api().load();
        await flushPromises();
        handBack({ n: 1 });
        await flushPromises();

        expect(wrapper.text()).toBe('2');
    });
});
