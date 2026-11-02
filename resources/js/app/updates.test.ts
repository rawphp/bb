import { effectScope, ref } from 'vue';
import { beforeEach, describe, expect, it, vi } from 'vitest';

type Listener = (event: { isExternal?: boolean }) => void;

// The waiting ServiceWorker: its state, and the statechange it fires.
class FakeWorker extends EventTarget {
    state = 'installed';
    activate() {
        this.become('activated');
    }
    become(state: string) {
        this.state = state;
        this.dispatchEvent(new Event('statechange'));
    }
}

const workbox = vi.hoisted(() => {
    const state = {
        url: '',
        options: {} as unknown,
        listeners: {} as Record<string, Listener[]>,
        // The registration register() resolves with; `waiting` is the version waiting to take over.
        registration: { waiting: null as FakeWorker | null },
        register: vi.fn(async (): Promise<unknown> => state.registration),
        update: vi.fn(async (): Promise<unknown> => undefined),
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
            removeEventListener: (type: string, listener: Listener) =>
                (state.listeners[type] = (state.listeners[type] ?? []).filter((each) => each !== listener)),
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
    await flushPromises();

    return updates;
}

const flushPromises = () => new Promise((resolve) => setTimeout(resolve));

// A new version installed and is waiting, as workbox-window reports it.
function newVersionWaits() {
    const next = new FakeWorker();
    workbox.state.registration.waiting = next;
    workbox.state.emit('waiting');
    return next;
}

// The worker controlling this tab, or null in a tab opened before the first worker activated.
function controlledBy(controller: object | null) {
    Object.defineProperty(navigator, 'serviceWorker', { value: { controller }, configurable: true });
}

describe('updates', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        workbox.state.registration.waiting = null;
        controlledBy({});
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

        newVersionWaits();
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

    it('PWA-035 hides the banner while any hold is open, such as a workout', async () => {
        const { updateBanner, holdUpdates } = await load();
        newVersionWaits();

        const releaseWorkout = holdUpdates();
        const releaseOther = holdUpdates();
        expect(updateBanner.value).toBe(false);

        releaseOther();
        releaseOther();
        expect(updateBanner.value).toBe(false);

        releaseWorkout();
        expect(updateBanner.value).toBe(true);
    });

    it('PWA-035 hides the banner while a sheet is open, and a tap then does nothing', async () => {
        const { applyUpdate, updateBanner } = await load();
        // The same module instance updates.ts reads after load() reset the registry.
        const { openSheets } = await import('../design/openSheets');
        newVersionWaits();
        const sheet = Symbol('sheet');

        openSheets.push(sheet);
        expect(updateBanner.value).toBe(false);
        applyUpdate();
        expect(workbox.state.messageSkipWaiting).not.toHaveBeenCalled();

        openSheets.splice(openSheets.indexOf(sheet), 1);
        expect(updateBanner.value).toBe(true);
    });

    it('PWA-035 does nothing on a tap while updates are held', async () => {
        const { applyUpdate, holdUpdates } = await load();
        newVersionWaits();
        holdUpdates();

        applyUpdate();

        expect(workbox.state.messageSkipWaiting).not.toHaveBeenCalled();
    });

    it('PWA-035 a tap activates the new version and reloads this tab once it takes control', async () => {
        const { applyUpdate } = await load();
        newVersionWaits();

        applyUpdate();
        expect(workbox.state.messageSkipWaiting).toHaveBeenCalled();
        expect(reload).not.toHaveBeenCalled();

        workbox.state.emit('controlling');
        expect(reload).toHaveBeenCalledTimes(1);
    });

    it('PWA-035 in a tab no worker controls yet, a tap reloads once the new version has activated', async () => {
        controlledBy(null);
        const { applyUpdate } = await load();
        const next = newVersionWaits();

        applyUpdate();
        expect(workbox.state.messageSkipWaiting).toHaveBeenCalledTimes(1);
        expect(reload).not.toHaveBeenCalled();

        next.activate();
        expect(reload).toHaveBeenCalledTimes(1);
    });

    it('PWA-035 a workout started after the tap holds the reload until it ends', async () => {
        const { applyUpdate, holdUpdates, updateBanner } = await load();
        newVersionWaits();

        applyUpdate();
        expect(updateBanner.value).toBe(false);
        const releaseWorkout = holdUpdates();
        workbox.state.emit('controlling');
        expect(reload).not.toHaveBeenCalled();

        releaseWorkout();
        expect(reload).toHaveBeenCalledTimes(1);
    });

    it('PWA-035 a sheet opened after the tap holds the reload in a tab no worker controls, until it closes', async () => {
        controlledBy(null);
        const { applyUpdate } = await load();
        const { openSheets } = await import('../design/openSheets');
        const next = newVersionWaits();
        const sheet = Symbol('sheet');

        applyUpdate();
        openSheets.push(sheet);
        next.activate();
        expect(reload).not.toHaveBeenCalled();

        openSheets.splice(openSheets.indexOf(sheet), 1);
        expect(reload).toHaveBeenCalledTimes(1);
    });

    it('PWA-035 a second tap adds nothing, and the reload happens once', async () => {
        const { applyUpdate } = await load();
        newVersionWaits();

        applyUpdate();
        applyUpdate();
        workbox.state.emit('controlling');
        workbox.state.emit('controlling');

        expect(workbox.state.messageSkipWaiting).toHaveBeenCalledTimes(1);
        expect(workbox.state.listeners.controlling).toHaveLength(1);
        expect(reload).toHaveBeenCalledTimes(1);
    });

    it('PWA-035 useHoldUpdates holds while active, and lets go when inactive or when its scope ends', async () => {
        const { useHoldUpdates, updateBanner } = await load();
        newVersionWaits();
        const active = ref(true);
        const scope = effectScope();

        scope.run(() => useHoldUpdates(active));
        expect(updateBanner.value).toBe(false);
        active.value = false;
        expect(updateBanner.value).toBe(true);
        active.value = true;
        expect(updateBanner.value).toBe(false);

        // The screen goes away by any path, still active: the hold goes with it.
        scope.stop();
        expect(updateBanner.value).toBe(true);
    });

    it('PWA-035 a tapped version replaced before it takes over leaves no stuck tap, and the next version brings the banner back', async () => {
        const { applyUpdate, updateBanner } = await load();
        const replaced = newVersionWaits();

        applyUpdate();
        replaced.become('redundant');
        workbox.state.emit('controlling');
        expect(reload).not.toHaveBeenCalled();
        expect(updateBanner.value).toBe(false);

        newVersionWaits();
        expect(updateBanner.value).toBe(true);
        applyUpdate();
        workbox.state.emit('controlling');
        expect(reload).toHaveBeenCalledTimes(1);
    });

    it('PWA-035 a tap reloads at once when another tab already activated the new version', async () => {
        const { applyUpdate } = await load();
        newVersionWaits();
        // Another tab's tap: the waiting version took over, and this tab still runs the old one.
        workbox.state.registration.waiting = null;
        workbox.state.emit('controlling', { isExternal: true });

        applyUpdate();

        expect(reload).toHaveBeenCalledTimes(1);
        expect(workbox.state.messageSkipWaiting).not.toHaveBeenCalled();
    });

    it('PWA-035 a version that waits after another tab activated the last one is still activated by a tap', async () => {
        const { applyUpdate } = await load();
        newVersionWaits();
        workbox.state.registration.waiting = null;
        workbox.state.emit('controlling', { isExternal: true });
        newVersionWaits();

        applyUpdate();

        expect(workbox.state.messageSkipWaiting).toHaveBeenCalledTimes(1);
        expect(reload).not.toHaveBeenCalled();
        workbox.state.emit('controlling');
        expect(reload).toHaveBeenCalledTimes(1);
    });

    it('PWA-035 reports a failed registration, and lets an offline update check fail quietly', async () => {
        const error = vi.spyOn(console, 'error').mockImplementation(() => undefined);
        try {
            workbox.state.register.mockRejectedValueOnce(new Error('404'));
            await load();
            expect(error).toHaveBeenCalledWith('The service worker did not register', expect.any(Error));

            const offline = Promise.reject(new TypeError('Failed to fetch'));
            const handled = vi.spyOn(offline, 'catch');
            workbox.state.update.mockReturnValue(offline);
            Object.defineProperty(document, 'visibilityState', { value: 'visible', configurable: true });
            document.dispatchEvent(new Event('visibilitychange'));
            await flushPromises();

            expect(handled).toHaveBeenCalled();
            expect(error).toHaveBeenCalledTimes(1);
        } finally {
            workbox.state.update.mockImplementation(async () => undefined);
            error.mockRestore();
        }
    });

    it('PWA-035 skips registration where service workers are missing', async () => {
        Reflect.deleteProperty(navigator, 'serviceWorker');

        await load();

        expect(workbox.Workbox).not.toHaveBeenCalled();
    });
});
