import { beforeEach, describe, expect, it, vi } from 'vitest';

type Listener = (event: { isExternal?: boolean }) => void;

const workbox = vi.hoisted(() => {
    const state = {
        url: '',
        options: {} as unknown,
        listeners: {} as Record<string, Listener[]>,
        register: vi.fn(async () => undefined),
        update: vi.fn(async () => undefined),
        messageSkipWaiting: vi.fn(),
        emit(type: string, event: { isExternal?: boolean } = {}) {
            for (const listener of state.listeners[type] ?? []) listener(event);
        },
    };
    const Workbox = vi.fn(function (this: object, url: string, options: unknown) {
        state.url = url;
        state.options = options;
        state.listeners = {};
        Object.assign(this, {
            addEventListener: (type: string, listener: Listener) => (state.listeners[type] ??= []).push(listener),
            register: state.register,
            update: state.update,
            messageSkipWaiting: state.messageSkipWaiting,
        });
    });

    return { state, Workbox };
});

vi.mock('workbox-window', () => ({ Workbox: workbox.Workbox }));

const reload = vi.fn();

async function load() {
    vi.resetModules();
    const updates = await import('./updates');
    updates.registerServiceWorker();

    return updates;
}

describe('updates', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        Object.defineProperty(navigator, 'serviceWorker', { value: {}, configurable: true });
        Object.defineProperty(window, 'location', { value: { reload }, configurable: true });
    });

    it('PWA-004 registers the worker at /sw.js so it controls every app path', async () => {
        await load();

        expect(workbox.state.url).toBe('/sw.js');
        expect(workbox.state.options).toEqual({ scope: '/' });
        expect(workbox.state.register).toHaveBeenCalled();
    });

    it('PWA-035 shows the banner once a new version is waiting, and never reloads by itself', async () => {
        const { updateBanner } = await load();
        expect(updateBanner.value).toBe(false);

        workbox.state.emit('waiting');
        workbox.state.emit('controlling', { isExternal: true });

        expect(updateBanner.value).toBe(true);
        expect(reload).not.toHaveBeenCalled();
    });

    it('PWA-035 checks for a new version when the app comes back to the foreground', async () => {
        await load();
        Object.defineProperty(document, 'visibilityState', { value: 'visible', configurable: true });

        document.dispatchEvent(new Event('visibilitychange'));

        expect(workbox.state.update).toHaveBeenCalled();
    });

    it('PWA-035 hides the banner while a workout or sheet holds updates', async () => {
        const { updateBanner, holdUpdates } = await load();
        workbox.state.emit('waiting');

        const releaseWorkout = holdUpdates();
        const releaseSheet = holdUpdates();
        expect(updateBanner.value).toBe(false);

        releaseSheet();
        releaseSheet();
        expect(updateBanner.value).toBe(false);

        releaseWorkout();
        expect(updateBanner.value).toBe(true);
    });

    it('PWA-035 does nothing on a tap while updates are held', async () => {
        const { applyUpdate, holdUpdates } = await load();
        workbox.state.emit('waiting');
        holdUpdates();

        applyUpdate();

        expect(workbox.state.messageSkipWaiting).not.toHaveBeenCalled();
    });

    it('PWA-035 a tap activates the new version and reloads this tab once it takes control', async () => {
        const { applyUpdate } = await load();
        workbox.state.emit('waiting');

        applyUpdate();
        expect(workbox.state.messageSkipWaiting).toHaveBeenCalled();
        expect(reload).not.toHaveBeenCalled();

        workbox.state.emit('controlling');
        expect(reload).toHaveBeenCalledTimes(1);
    });

    it('PWA-035 a tap reloads at once when another tab already activated the new version', async () => {
        const { applyUpdate } = await load();
        workbox.state.emit('waiting');
        workbox.state.emit('controlling', { isExternal: true });

        applyUpdate();

        expect(reload).toHaveBeenCalledTimes(1);
        expect(workbox.state.messageSkipWaiting).not.toHaveBeenCalled();
    });

    it('PWA-035 skips registration where service workers are missing', async () => {
        Reflect.deleteProperty(navigator, 'serviceWorker');

        await load();

        expect(workbox.Workbox).not.toHaveBeenCalled();
    });
});
