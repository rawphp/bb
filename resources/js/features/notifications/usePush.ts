import { computed, ref } from 'vue';
import { read, write } from '@/app/call';
import type { PushShowOutput } from '@/contracts/push_show';
import type { PushSubscribeInput } from '@/contracts/push_subscribe';
import type { PushUnsubscribeInput } from '@/contracts/push_unsubscribe';

// Web Push on this device (NOTIF-010). Nothing here asks for permission except
// enable(), which Settings and onboarding's "Remind me" call once the user has
// opted in. The server decides when a notification is due and sends it.
//
// unavailable: the instance has no VAPID keys, or the browser has no Web Push.
// needs-home-screen: iPhone or iPad Safari, which only offers Web Push to the
// app installed to the home screen.
export type PushState = 'unavailable' | 'needs-home-screen' | 'denied' | 'off' | 'on';

export function usePush() {
    const state = ref<PushState | null>(null);
    let publicKey: string | null = null;

    async function refresh(): Promise<PushState> {
        state.value = await currentState();
        return state.value;
    }

    async function currentState(): Promise<PushState> {
        if (isIos() && !isStandalone()) return 'needs-home-screen';
        if (!browserSupportsPush()) return 'unavailable';

        const push = await read<PushShowOutput>('push_show');
        publicKey = push.available ? (push.public_key ?? null) : null;
        if (publicKey === null) return 'unavailable';
        if (Notification.permission === 'denied') return 'denied';

        const registration = await navigator.serviceWorker.getRegistration();
        const subscription = await registration?.pushManager.getSubscription();
        if (!subscription) return 'off';

        // The server's row follows whoever is signed in on this browser, so a
        // browser that switched accounts moves to this one. The save is an upsert.
        await save(subscription);
        return 'on';
    }

    async function save(subscription: PushSubscription): Promise<void> {
        const { endpoint, keys } = subscription.toJSON();

        await write('push_subscribe', {
            endpoint: endpoint ?? subscription.endpoint,
            public_key: keys?.p256dh ?? '',
            auth_token: keys?.auth ?? '',
        } satisfies PushSubscribeInput);
    }

    async function enable(): Promise<PushState> {
        const before = await refresh();
        if (before !== 'off' || publicKey === null) return before;

        const permission = await Notification.requestPermission();
        if (permission !== 'granted') {
            state.value = permission === 'denied' ? 'denied' : 'off';
            return state.value;
        }

        const registration = await navigator.serviceWorker.ready;
        const subscription =
            (await registration.pushManager.getSubscription()) ??
            (await registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: base64UrlToBytes(publicKey) }));

        // A failed save leaves the state 'off' and rejects; the next refresh() saves again.
        await save(subscription);

        state.value = 'on';
        return state.value;
    }

    async function disable(): Promise<void> {
        const registration = await navigator.serviceWorker?.getRegistration();
        const subscription = await registration?.pushManager.getSubscription();

        // The browser stops receiving even when the server can't be told: its
        // endpoint is then dead, and the next send deletes the row.
        try {
            if (subscription) await write('push_unsubscribe', { endpoint: subscription.endpoint } satisfies PushUnsubscribeInput);
        } finally {
            await subscription?.unsubscribe();
            if (state.value === 'on') state.value = 'off';
        }
    }

    return { state, needsHomeScreen: computed(() => state.value === 'needs-home-screen'), refresh, enable, disable };
}

function browserSupportsPush(): boolean {
    return 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
}

// iPadOS reports itself as a Mac; a touch screen tells them apart.
function isIos(): boolean {
    const agent = navigator.userAgent;
    return /iPhone|iPad|iPod/.test(agent) || (agent.includes('Macintosh') && navigator.maxTouchPoints > 1);
}

function isStandalone(): boolean {
    return window.matchMedia?.('(display-mode: standalone)').matches || (navigator as { standalone?: boolean }).standalone === true;
}

function base64UrlToBytes(value: string): Uint8Array<ArrayBuffer> {
    const base64 = value.replace(/-/g, '+').replace(/_/g, '/').padEnd(Math.ceil(value.length / 4) * 4, '=');
    return Uint8Array.from(atob(base64), (char) => char.charCodeAt(0));
}
