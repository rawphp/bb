import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';
import { CapabilityError, read } from '@/app/call';
import { useScreenRead } from './useScreenRead';

vi.mock('@/app/call', async (original) => ({ ...(await original<typeof import('@/app/call')>()), read: vi.fn() }));

const readMock = vi.mocked(read);

type Data = { title: string };

function mountScreen(payload?: Data) {
    let screen!: ReturnType<typeof useScreenRead<Data>>;
    const wrapper = mount(
        defineComponent({
            setup() {
                screen = useScreenRead<Data>('thing_show', { id: 7 }, payload);
                return () => h('div');
            },
        }),
    );
    return { wrapper, screen };
}

describe('useScreenRead', () => {
    beforeEach(() => readMock.mockReset());

    it('VIEW-001 shows the payload it is handed and never reads', async () => {
        const { screen } = mountScreen({ title: 'Fixture' });
        await flushPromises();

        expect(screen.data.value).toEqual({ title: 'Fixture' });
        expect(screen.failure.value).toBeNull();
        expect(readMock).not.toHaveBeenCalled();
    });

    it('CAP-004 reads the capability with its input on mount, then holds what it returned', async () => {
        readMock.mockResolvedValueOnce({ title: 'Server' });
        const { screen } = mountScreen();

        expect(screen.data.value).toBeUndefined();
        await flushPromises();

        expect(readMock).toHaveBeenCalledWith('thing_show', { id: 7 }, expect.any(Function), expect.any(Function));
        expect(screen.data.value).toEqual({ title: 'Server' });
        expect(screen.failure.value).toBeNull();
    });

    it.each([
        [new CapabilityError('thing_show', 401, null), 'signed-out'],
        [new CapabilityError('thing_show', 404, null), 'error'],
        [new CapabilityError('thing_show', 0, null), 'error'],
        [new Error('boom'), 'error'],
    ])('CAP-004 a read that throws %s ends in %s', async (error, failure) => {
        readMock.mockRejectedValueOnce(error);
        const { screen } = mountScreen();
        await flushPromises();

        expect(screen.data.value).toBeUndefined();
        expect(screen.failure.value).toBe(failure);
    });

    it('OFF-003 a refresh replaces the cached copy, and a refused refresh drops it', async () => {
        let refresh: (data: Data) => void = () => {};
        let refuse: (error: CapabilityError) => void = () => {};
        readMock.mockImplementationOnce(async (_name, _input, onUpdate, onError) => {
            refresh = onUpdate as typeof refresh;
            refuse = onError as typeof refuse;
            return { title: 'Cached' };
        });
        const { screen } = mountScreen();
        await flushPromises();
        expect(screen.data.value).toEqual({ title: 'Cached' });

        refresh({ title: 'Fresh' });
        expect(screen.data.value).toEqual({ title: 'Fresh' });

        refuse(new CapabilityError('thing_show', 401, null));
        expect(screen.data.value).toBeUndefined();
        expect(screen.failure.value).toBe('signed-out');
    });

    it('OFF-003 a refresh that lands before read() returns wins over the cached copy', async () => {
        readMock.mockImplementationOnce(async (_name, _input, onUpdate) => {
            (onUpdate as (data: Data) => void)({ title: 'Fresh' });
            return { title: 'Cached' };
        });
        const { screen } = mountScreen();
        await flushPromises();

        expect(screen.data.value).toEqual({ title: 'Fresh' });
    });

    it('OFF-003 a refusal that lands before read() returns keeps the cached copy away', async () => {
        readMock.mockImplementationOnce(async (_name, _input, _onUpdate, onError) => {
            (onError as (error: CapabilityError) => void)(new CapabilityError('thing_show', 404, null));
            return { title: 'Cached' };
        });
        const { screen } = mountScreen();
        await flushPromises();

        expect(screen.data.value).toBeUndefined();
        expect(screen.failure.value).toBe('error');
    });
});
