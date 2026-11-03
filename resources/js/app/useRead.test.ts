import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, ref } from 'vue';
import { cached } from './cache';
import { CapabilityError, read, readFresh, WARM_FRESH_MS } from './call';
import { announceReplayed } from './outbox';
import ScreenFrame from './ScreenFrame.vue';
import { SupersededRead, type ReadFailure } from './screenRead';
import { useRead } from './useRead';

vi.mock('./call', async (original) => ({ ...(await original<typeof import('./call')>()), read: vi.fn(), readFresh: vi.fn() }));

vi.mock('./cache', async (original) => ({ ...(await original<typeof import('./cache')>()), cached: vi.fn() }));

const cachedMock = vi.mocked(cached);
const readMock = vi.mocked(read);
const readFreshMock = vi.mocked(readFresh);

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
    beforeEach(() => {
        readMock.mockReset();
        readFreshMock.mockReset();
        cachedMock.mockReset().mockResolvedValue(undefined);
    });

    it('CAP-002 shows a given payload and reads nothing', () => {
        const { wrapper } = screen({ n: 1 });

        expect(wrapper.text()).toBe('1');
        expect(readMock).not.toHaveBeenCalled();
    });

    it('CAP-010 reads again when a replayed outbox entry invalidates the read on screen, and not for another read', async () => {
        readMock.mockResolvedValueOnce({ n: 1 }).mockResolvedValueOnce({ n: 2 });
        const { wrapper } = screen();
        await flushPromises();

        announceReplayed(['other_show{}']);
        await flushPromises();
        expect(readMock).toHaveBeenCalledTimes(1);

        announceReplayed(['probe_show{"id":7}']);
        await flushPromises();
        expect(readMock).toHaveBeenCalledTimes(2);
        expect(wrapper.text()).toBe('2');

        wrapper.unmount();
        announceReplayed(['probe_show{"id":7}']);
        expect(readMock).toHaveBeenCalledTimes(2);
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

    it('CAP-010 reads again when its screen comes back on top with no cached copy, but not a fixture', async () => {
        readMock.mockResolvedValueOnce({ n: 1 }).mockResolvedValueOnce({ n: 2 });
        const top = ref(true);
        const Probe = defineComponent(
            (props: { payload?: { n: number } }) => {
                const api = useRead('probe_show', {}, 'No.', props.payload);
                return () => h('p', String(api.data.value?.n ?? 'loading'));
            },
            { props: ['payload'] },
        );
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

    it('OFF-011 load({ fresh: true }) shows the server\'s answer, never the cached copy', async () => {
        readMock.mockResolvedValueOnce({ n: 1 });
        readFreshMock.mockResolvedValueOnce({ n: 5 });
        const { wrapper, api } = screen();
        await flushPromises();

        await api().load({ fresh: true });
        await flushPromises();

        expect(readFreshMock).toHaveBeenCalledWith('probe_show', { id: 7 });
        expect(readMock).toHaveBeenCalledTimes(1);
        expect(wrapper.text()).toBe('5');
    });

    it.each([0, 500])('OFF-003 keeps showing the screen when a fresh read gets no answer (%i), and rejects so the caller can tell', async (status) => {
        readMock.mockResolvedValueOnce({ n: 1 });
        const failure = new CapabilityError('probe_show', status, null);
        readFreshMock.mockRejectedValueOnce(failure);
        const { wrapper, api } = screen();
        await flushPromises();

        await expect(api().load({ fresh: true })).rejects.toBe(failure);
        await flushPromises();

        expect(wrapper.text()).toBe('1');
    });

    it('OFF-003 a fresh read says why it failed when there was nothing to keep showing', async () => {
        readMock.mockRejectedValueOnce(new CapabilityError('probe_show', 500, null));
        readFreshMock.mockRejectedValueOnce(new CapabilityError('probe_show', 0, { reason: 'network' }));
        const { wrapper, api } = screen();
        await flushPromises();
        expect(wrapper.text()).toBe('error');

        await api().load({ fresh: true }).catch(() => undefined);
        await flushPromises();

        expect(wrapper.text()).toBe('offline');
    });

    it('OFF-003 stops showing the screen when a fresh read is refused', async () => {
        readMock.mockResolvedValueOnce({ n: 1 });
        readFreshMock.mockRejectedValueOnce(new CapabilityError('probe_show', 401, null));
        const { wrapper, api } = screen();
        await flushPromises();

        await api().load({ fresh: true }).catch(() => undefined);
        await flushPromises();

        expect(wrapper.text()).toBe('signed-out');
    });

    it('OFF-003 an older refresh that lands after a fresh read cannot replace its answer', async () => {
        let staleRefresh: (d: { n: number }) => void = () => {};
        readMock.mockImplementationOnce(async (_name, _input, onUpdate) => {
            staleRefresh = onUpdate as typeof staleRefresh;
            return { n: 1 };
        });
        readFreshMock.mockResolvedValueOnce({ n: 5 });
        const { wrapper, api } = screen();
        await flushPromises();

        await api().load({ fresh: true });
        staleRefresh({ n: 2 });
        await flushPromises();

        expect(wrapper.text()).toBe('5');
    });

    it('CAP-002 a plain load never rejects', async () => {
        readMock.mockResolvedValueOnce({ n: 1 }).mockRejectedValueOnce(new CapabilityError('probe_show', 500, null));
        const { api } = screen();
        await flushPromises();

        await expect(api().load()).resolves.toBeUndefined();
    });

    it.each([
        ['younger than a warm keeps', WARM_FRESH_MS - 5_000, 1],
        ['older than a warm keeps', WARM_FRESH_MS + 5_000, 2],
    ])('OFF-004 on coming back on top, a cached copy %s decides whether it reads again', async (_label, age, reads) => {
        readMock.mockResolvedValue({ n: 1 });
        const top = ref(true);
        const Probe = defineComponent(() => {
            const api = useRead<{ n: number }>('probe_show', {}, 'No.');
            return () => h('p', String(api.data.value?.n ?? 'loading'));
        });
        mount(defineComponent(() => () => h(ScreenFrame, { top: top.value }, () => h(Probe))));
        await flushPromises();
        cachedMock.mockResolvedValue({ key: 'probe_show', data: { n: 1 }, fetchedAt: Date.now() - age });

        top.value = false;
        await flushPromises();
        top.value = true;
        await flushPromises();

        expect(readMock).toHaveBeenCalledTimes(reads);
    });

    it('OFF-003 a fresh read that a newer load overtakes rejects, and the newer load\'s answer stays', async () => {
        let answerFresh: (d: { n: number }) => void = () => {};
        readMock.mockResolvedValueOnce({ n: 1 }).mockResolvedValueOnce({ n: 2 });
        readFreshMock.mockImplementationOnce(() => new Promise((resolve) => (answerFresh = resolve as typeof answerFresh)));
        const { wrapper, api } = screen();
        await flushPromises();

        const fresh = api().load({ fresh: true });
        await api().load();
        answerFresh({ n: 5 });

        await expect(fresh).rejects.toBeInstanceOf(SupersededRead);
        await flushPromises();
        expect(wrapper.text()).toBe('2');
    });

    it('OFF-003 an overtaken fresh read leaves an empty screen to the newer load, showing no failure', async () => {
        readMock.mockImplementation(() => new Promise(() => {}));
        readFreshMock.mockResolvedValueOnce({ n: 5 });
        const { wrapper, api } = screen();

        const fresh = api().load({ fresh: true });
        void api().load();

        await expect(fresh).rejects.toBeInstanceOf(SupersededRead);
        await flushPromises();
        expect(wrapper.text()).toBe('loading');
    });

    it('CAP-010 reads again on coming back on top when a pushed screen cached a newer copy than the one shown', async () => {
        readMock.mockResolvedValueOnce({ n: 1 }).mockResolvedValueOnce({ n: 2 });
        const top = ref(true);
        const Probe = defineComponent(() => {
            const api = useRead<{ n: number }>('probe_show', {}, 'No.');
            return () => h('p', String(api.data.value?.n ?? 'loading'));
        });
        const wrapper = mount(defineComponent(() => () => h(ScreenFrame, { top: top.value }, () => h(Probe))));
        await flushPromises();
        // The pushed screen's read after its write stored this copy after ours was shown.
        cachedMock.mockResolvedValue({ key: 'probe_show', data: { n: 2 }, fetchedAt: Date.now() + 1_000 });

        top.value = false;
        await flushPromises();
        top.value = true;
        await flushPromises();

        expect(readMock).toHaveBeenCalledTimes(2);
        expect(wrapper.text()).toBe('2');
    });

    it('CAP-004 an input given as a function is read as it is at each load, and replays and the cache follow it', async () => {
        readMock.mockResolvedValue({ n: 1 });
        const day = ref('2026-11-02');
        const top = ref(true);
        let api!: ReturnType<typeof useRead<{ n: number }>>;
        const Probe = defineComponent(() => {
            api = useRead('probe_show', () => ({ day: day.value }), 'No.');
            return () => h('p', String(api.data.value?.n ?? 'loading'));
        });
        mount(defineComponent(() => () => h(ScreenFrame, { top: top.value }, () => h(Probe))));
        await flushPromises();
        expect(readMock).toHaveBeenLastCalledWith('probe_show', { day: '2026-11-02' }, expect.any(Function), expect.any(Function));

        day.value = '2026-11-09';
        await api.load();
        expect(readMock).toHaveBeenLastCalledWith('probe_show', { day: '2026-11-09' }, expect.any(Function), expect.any(Function));

        announceReplayed(['probe_show{"day":"2026-11-02"}']);
        await flushPromises();
        expect(readMock).toHaveBeenCalledTimes(2);
        announceReplayed(['probe_show{"day":"2026-11-09"}']);
        await flushPromises();
        expect(readMock).toHaveBeenCalledTimes(3);

        top.value = false;
        await flushPromises();
        top.value = true;
        await flushPromises();
        expect(cachedMock).toHaveBeenLastCalledWith('probe_show{"day":"2026-11-09"}');
    });
});
