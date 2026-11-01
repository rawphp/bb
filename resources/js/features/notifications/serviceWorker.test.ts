import { describe, expect, it, vi } from 'vitest';
import { notificationFrom, openTarget, targetUrl } from './serviceWorker';

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
});
