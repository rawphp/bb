import { describe, expect, it, vi } from 'vitest';
import { listenForPush, notificationFrom, openTarget, targetUrl } from './serviceWorker';

const ORIGIN = 'https://bodybuilder.test';

describe('push service worker', () => {
    it('NOTIF-011 shows the title and body and keeps the URL on the notification', () => {
        expect(notificationFrom({ title: 'Weigh-in', body: 'Time to weigh in.', data: { url: '/today/weigh-in' } })).toEqual([
            'Weigh-in',
            { body: 'Time to weigh in.', data: { url: '/today/weigh-in' } },
        ]);
    });

    it('NOTIF-011 opens the screen named in the payload', () => {
        expect(targetUrl({ url: '/workouts/01JBX8Z6Q7?from=push' }, ORIGIN)).toBe(`${ORIGIN}/workouts/01JBX8Z6Q7?from=push`);
    });

    it.each([[{ url: 'https://evil.example.com/' }], [{ url: '//evil.example.com/' }], [{}], [null]])(
        'NOTIF-011 opens the app\'s start for a URL outside the app (%j)',
        (data) => {
            expect(targetUrl(data, ORIGIN)).toBe(`${ORIGIN}/`);
        },
    );

    it('NOTIF-011 reuses an open window of the app', async () => {
        const client = { navigate: vi.fn(async () => client), focus: vi.fn(async () => client) };
        const clients = { matchAll: vi.fn(async () => [client]), openWindow: vi.fn() };

        await openTarget(clients as unknown as Clients, `${ORIGIN}/today/weigh-in`);

        expect(client.navigate).toHaveBeenCalledWith(`${ORIGIN}/today/weigh-in`);
        expect(client.focus).toHaveBeenCalled();
        expect(clients.openWindow).not.toHaveBeenCalled();
    });

    it('NOTIF-011 opens a new window when the app is not open', async () => {
        const clients = { matchAll: vi.fn(async () => []), openWindow: vi.fn() };

        await openTarget(clients as unknown as Clients, `${ORIGIN}/today/weigh-in`);

        expect(clients.openWindow).toHaveBeenCalledWith(`${ORIGIN}/today/weigh-in`);
    });

    it('NOTIF-011 tries the next window when one cannot be navigated', async () => {
        const stuck = { navigate: vi.fn(async () => Promise.reject(new TypeError('uncontrolled'))), focus: vi.fn() };
        const open = { navigate: vi.fn(async () => open), focus: vi.fn(async () => open) };
        const clients = { matchAll: vi.fn(async () => [stuck, open]), openWindow: vi.fn() };

        await openTarget(clients as unknown as Clients, `${ORIGIN}/today`);

        expect(open.focus).toHaveBeenCalled();
        expect(clients.openWindow).not.toHaveBeenCalled();
    });

    it('NOTIF-011 opens a new window when no open window can be navigated', async () => {
        const stuck = { navigate: vi.fn(async () => Promise.reject(new TypeError('uncontrolled'))), focus: vi.fn() };
        const clients = { matchAll: vi.fn(async () => [stuck]), openWindow: vi.fn() };

        await openTarget(clients as unknown as Clients, `${ORIGIN}/today`);

        expect(clients.openWindow).toHaveBeenCalledWith(`${ORIGIN}/today`);
    });

    describe('listenForPush', () => {
        type Handler = (event: unknown) => void;

        function fakeWorker() {
            const handlers: Record<string, Handler> = {};
            const showNotification = vi.fn(async () => undefined);
            const client = { navigate: vi.fn(async () => client), focus: vi.fn(async () => client) };
            const clients = { matchAll: vi.fn(async () => [client]), openWindow: vi.fn() };
            const worker = {
                addEventListener: (type: string, handler: Handler) => (handlers[type] = handler),
                registration: { showNotification },
                clients,
                location: { origin: ORIGIN },
            };
            listenForPush(worker as unknown as ServiceWorkerGlobalScope);

            return { handlers, showNotification, client, clients };
        }

        async function dispatch(handler: Handler | undefined, event: Record<string, unknown>): Promise<void> {
            let work: Promise<unknown> = Promise.resolve();
            handler!({ ...event, waitUntil: (promise: Promise<unknown>) => (work = promise) });
            await work;
        }

        it('NOTIF-011 shows a JSON push', async () => {
            const { handlers, showNotification } = fakeWorker();
            const payload = { title: 'Weigh-in', body: 'Time to weigh in.', data: { url: '/today/weigh-in' } };

            await dispatch(handlers.push, { data: { json: () => payload, text: () => JSON.stringify(payload) } });

            expect(showNotification).toHaveBeenCalledWith('Weigh-in', { body: 'Time to weigh in.', data: { url: '/today/weigh-in' } });
        });

        it('NOTIF-011 shows a plain-text push as the body under the app name', async () => {
            const { handlers, showNotification } = fakeWorker();

            await dispatch(handlers.push, {
                data: {
                    json: () => {
                        throw new SyntaxError('not json');
                    },
                    text: () => 'Time to weigh in.',
                },
            });

            expect(showNotification).toHaveBeenCalledWith('Bodybuilder', { body: 'Time to weigh in.', data: undefined });
        });

        it('NOTIF-011 a tap closes the notification and opens its screen', async () => {
            const { handlers, client } = fakeWorker();
            const close = vi.fn();

            await dispatch(handlers.notificationclick, { notification: { close, data: { url: '/today/weigh-in' } } });

            expect(close).toHaveBeenCalled();
            expect(client.navigate).toHaveBeenCalledWith(`${ORIGIN}/today/weigh-in`);
        });
    });
});
