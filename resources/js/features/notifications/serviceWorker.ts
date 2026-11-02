/// <reference lib="webworker" />

// The service worker's side of Web Push. The server sends a WebPushMessage:
// { title, body, data: { url } }. Tapping the notification opens data.url,
// the screen it is about (NOTIF-011).

type PushPayload = { title?: unknown; body?: unknown; data?: unknown };

export function notificationFrom(payload: PushPayload): [string, NotificationOptions] {
    const title = typeof payload.title === 'string' ? payload.title : 'Bodybuilder';
    const body = typeof payload.body === 'string' ? payload.body : undefined;

    return [title, { body, data: payload.data }];
}

// Only a path in this app; anything else opens the app's start.
export function targetUrl(data: unknown, origin: string): string {
    const url = typeof data === 'object' && data !== null ? (data as { url?: unknown }).url : undefined;
    if (typeof url !== 'string' || !url.startsWith('/') || url.startsWith('//')) return `${origin}/`;

    const target = new URL(url, origin);
    return target.origin === origin ? target.href : `${origin}/`;
}

// Reuse an open window of the app when there is one, else open a new one.
export async function openTarget(clients: Clients, url: string): Promise<void> {
    const windows = await clients.matchAll({ type: 'window', includeUncontrolled: true });

    for (const client of windows) {
        try {
            const navigated = await client.navigate(url);
            await (navigated ?? client).focus();
            return;
        } catch {
            // An uncontrolled window cannot be navigated; try the next one.
        }
    }

    await clients.openWindow(url);
}

export function listenForPush(worker: ServiceWorkerGlobalScope): void {
    worker.addEventListener('push', (event) => {
        let payload: PushPayload = {};
        try {
            payload = (event.data?.json() as PushPayload | undefined) ?? {};
        } catch {
            payload = { body: event.data?.text() };
        }

        event.waitUntil(worker.registration.showNotification(...notificationFrom(payload)));
    });

    worker.addEventListener('notificationclick', (event) => {
        event.notification.close();
        event.waitUntil(openTarget(worker.clients, targetUrl(event.notification.data, worker.location.origin)));
    });
}
