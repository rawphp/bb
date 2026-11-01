import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { read, write } from '@/app/call';
import { usePush } from './usePush';

vi.mock('@/app/call', async (original) => ({ ...(await original<typeof import('@/app/call')>()), read: vi.fn(), write: vi.fn() }));

const readMock = vi.mocked(read);
const writeMock = vi.mocked(write);

const IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Mobile/15E148 Safari/604.1';
const ANDROID = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Mobile Safari/537.36';

type FakeSubscription = { endpoint: string; toJSON: () => PushSubscriptionJSON; unsubscribe: ReturnType<typeof vi.fn> };

function fakeSubscription(): FakeSubscription {
    return {
        endpoint: 'https://push.example.com/send/device-1',
        toJSON: () => ({ endpoint: 'https://push.example.com/send/device-1', keys: { p256dh: 'p256dh-key', auth: 'auth-key' } }),
        unsubscribe: vi.fn().mockResolvedValue(true),
    };
}

function installBrowser(options: { agent?: string; standalone?: boolean; permission?: NotificationPermission; answer?: NotificationPermission; subscribed?: boolean } = {}) {
    let current: FakeSubscription | null = options.subscribed ? fakeSubscription() : null;
    const pushManager = {
        getSubscription: vi.fn(async () => current),
        subscribe: vi.fn(async () => (current = fakeSubscription())),
    };
    const registration = { pushManager };
    const requestPermission = vi.fn(async () => options.answer ?? 'granted');

    vi.stubGlobal('navigator', {
        userAgent: options.agent ?? ANDROID,
        maxTouchPoints: 5,
        serviceWorker: { getRegistration: vi.fn(async () => registration), ready: Promise.resolve(registration) },
    });
    vi.stubGlobal('PushManager', function PushManager() {});
    vi.stubGlobal('Notification', { permission: options.permission ?? 'default', requestPermission });
    vi.stubGlobal('matchMedia', () => ({ matches: options.standalone ?? false }));

    return { pushManager, requestPermission };
}

describe('usePush', () => {
    beforeEach(() => {
        readMock.mockReset().mockResolvedValue({ available: true, public_key: 'BEl62iUYgUivxIkv69yViEuiBIa-Ib9-SkvMeAtA3LFgDzkrxZJjSgSnfckjBJuBkr3qBUYIHBQFLXYp5Nksh8U', devices: 0 });
        writeMock.mockReset().mockResolvedValue({ invalidates: ['push_show{}'], toast: null, undo: null });
    });

    afterEach(() => vi.unstubAllGlobals());

    it('NOTIF-010 never asks for permission until the user opts in', async () => {
        const { requestPermission } = installBrowser();
        const push = usePush();

        expect(await push.refresh()).toBe('off');
        expect(requestPermission).not.toHaveBeenCalled();
    });

    it('NOTIF-010 asks on opt-in, subscribes with the server key and saves the subscription', async () => {
        const { requestPermission, pushManager } = installBrowser();
        const push = usePush();

        expect(await push.enable()).toBe('on');

        expect(requestPermission).toHaveBeenCalledOnce();
        expect(pushManager.subscribe).toHaveBeenCalledWith(expect.objectContaining({ userVisibleOnly: true, applicationServerKey: expect.any(Uint8Array) }));
        expect(writeMock).toHaveBeenCalledWith('push_subscribe', {
            endpoint: 'https://push.example.com/send/device-1',
            public_key: 'p256dh-key',
            auth_token: 'auth-key',
        });
        expect(push.state.value).toBe('on');
    });

    it('NOTIF-010 saves nothing when the user refuses permission', async () => {
        const { pushManager } = installBrowser({ answer: 'denied' });
        const push = usePush();

        expect(await push.enable()).toBe('denied');
        expect(pushManager.subscribe).not.toHaveBeenCalled();
        expect(writeMock).not.toHaveBeenCalled();
    });

    it('NOTIF-010 says the app needs the home screen on iPhone Safari, without asking', async () => {
        const { requestPermission } = installBrowser({ agent: IPHONE, standalone: false });
        const push = usePush();

        expect(await push.enable()).toBe('needs-home-screen');
        expect(push.needsHomeScreen.value).toBe(true);
        expect(requestPermission).not.toHaveBeenCalled();
    });

    it('NOTIF-010 offers notifications on iPhone once the app runs from the home screen', async () => {
        installBrowser({ agent: IPHONE, standalone: true });
        const push = usePush();

        expect(await push.refresh()).toBe('off');
        expect(push.needsHomeScreen.value).toBe(false);
    });

    it('NOTIF-010 is unavailable when the instance has no VAPID keys', async () => {
        const { requestPermission } = installBrowser();
        readMock.mockResolvedValueOnce({ available: false, public_key: null, devices: 0 });
        const push = usePush();

        expect(await push.enable()).toBe('unavailable');
        expect(requestPermission).not.toHaveBeenCalled();
    });

    it('NOTIF-010 turning off removes the subscription from the server and the browser', async () => {
        installBrowser({ permission: 'granted', subscribed: true });
        const push = usePush();
        expect(await push.refresh()).toBe('on');
        const subscription = await (await navigator.serviceWorker.getRegistration())!.pushManager.getSubscription();

        await push.disable();

        expect(writeMock).toHaveBeenCalledWith('push_unsubscribe', { endpoint: 'https://push.example.com/send/device-1' });
        expect((subscription as unknown as FakeSubscription).unsubscribe).toHaveBeenCalledOnce();
        expect(push.state.value).toBe('off');
    });
});
