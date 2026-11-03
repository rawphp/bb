import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { CapabilityError, writeNow } from './call';
import { onReplayed } from './outbox';
import { RESEND_MS, syncTimeZone } from './timeZone';

vi.mock('./call', async (original) => ({ ...(await original<typeof import('./call')>()), writeNow: vi.fn() }));
const writeNowMock = vi.mocked(writeNow);

function signIn(owner: string): void {
    document.cookie = `bb_cache_owner=${owner}; path=/`;
}

describe('syncTimeZone', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-10-09T21:58:00Z'));
        localStorage.clear();
        signIn('a1');
        writeNowMock.mockReset().mockResolvedValue({ invalidates: [] });
    });

    afterEach(() => {
        vi.useRealTimers();
        document.cookie = 'bb_cache_owner=; path=/; expires=Thu, 01 Jan 1970 00:00:00 GMT';
    });

    it('SET-012 sends the device zone the first time', async () => {
        await syncTimeZone('Australia/Brisbane');

        expect(writeNowMock).toHaveBeenCalledExactlyOnceWith('time_zone_set', { time_zone: 'Australia/Brisbane' });
    });

    it('SET-012 does not send the zone the server already took on this device again within a day', async () => {
        await syncTimeZone('Australia/Brisbane');
        vi.setSystemTime(new Date('2026-10-10T21:57:00Z'));
        await syncTimeZone('Australia/Brisbane');

        expect(writeNowMock).toHaveBeenCalledTimes(1);
    });

    it('SET-012 sends at once when the device lands in another zone', async () => {
        await syncTimeZone('Australia/Brisbane');
        await syncTimeZone('Asia/Tokyo');

        expect(writeNowMock).toHaveBeenCalledTimes(2);
        expect(writeNowMock).toHaveBeenLastCalledWith('time_zone_set', { time_zone: 'Asia/Tokyo' });
    });

    it('SET-012 sends the same zone again after a day, so another device that changed it is put right', async () => {
        await syncTimeZone('Australia/Brisbane');
        vi.setSystemTime(new Date(new Date('2026-10-09T21:58:00Z').getTime() + RESEND_MS));
        await syncTimeZone('Australia/Brisbane');

        expect(writeNowMock).toHaveBeenCalledTimes(2);
    });

    it('SET-012 sends again for another user signed in on the same device', async () => {
        await syncTimeZone('Australia/Brisbane');
        signIn('b2');
        await syncTimeZone('Australia/Brisbane');

        expect(writeNowMock).toHaveBeenCalledTimes(2);
    });

    it('SET-012 a refused write remembers nothing and shows nothing, so the next load sends again', async () => {
        writeNowMock.mockRejectedValueOnce(new CapabilityError('time_zone_set', 422, { error: { code: 'validation_failed' } }));

        await expect(syncTimeZone('Australia/Brisbane')).resolves.toBeUndefined();
        await syncTimeZone('Australia/Brisbane');

        expect(writeNowMock).toHaveBeenCalledTimes(2);
    });

    it('SET-012 tells mounted screens about the reads a changed zone dropped, and nothing when it is unchanged', async () => {
        const heard: (readonly string[])[] = [];
        const stop = onReplayed((keys) => heard.push(keys));
        writeNowMock.mockResolvedValueOnce({ invalidates: ['today_show{}', 'week_show{}'] });

        await syncTimeZone('Australia/Brisbane');
        await syncTimeZone('Asia/Tokyo');
        stop();

        expect(heard).toEqual([['today_show{}', 'week_show{}']]);
    });
});
