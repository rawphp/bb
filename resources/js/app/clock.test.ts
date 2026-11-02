import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { clearWalk, isWalked, now, walkDays, walkToDate } from './clock';

const DAY = 86_400_000;

describe('clock', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date(2026, 10, 2, 9, 30));
        clearWalk();
    });

    afterEach(() => vi.useRealTimers());

    it('SET-011 follows the device clock with no offset', () => {
        expect(isWalked()).toBe(false);
        expect(now()).toEqual(new Date(2026, 10, 2, 9, 30));
    });

    it('SET-011 walks forward a day and a week at a time', () => {
        walkDays(1);
        walkDays(7);

        expect(isWalked()).toBe(true);
        expect(now().getTime()).toBe(new Date(2026, 10, 2, 9, 30).getTime() + 8 * DAY);
    });

    it('SET-011 walks to a picked date at the current time of day', () => {
        walkToDate('2027-01-15');

        expect(now()).toEqual(new Date(2027, 0, 15, 9, 30));
    });

    it('SET-011 keeps walking with the device clock', () => {
        walkDays(1);
        vi.advanceTimersByTime(60_000);

        expect(now()).toEqual(new Date(2026, 10, 3, 9, 31));
    });

    it('SET-011 keeps the offset in local storage and clears it', async () => {
        walkDays(7);
        expect(localStorage.getItem('bb.clock-offset')).toBe(String(7 * DAY));

        vi.resetModules();
        expect((await import('./clock')).isWalked()).toBe(true);

        clearWalk();
        expect(localStorage.getItem('bb.clock-offset')).toBeNull();
        expect(now()).toEqual(new Date(2026, 10, 2, 9, 30));
    });

    it('SET-011 ignores a picker value that is not a date', () => {
        walkToDate('');

        expect(isWalked()).toBe(false);
    });

    it('SET-011 never walks into the past', async () => {
        walkDays(-1);
        expect(isWalked()).toBe(false);

        walkDays(7);
        walkToDate('2026-10-01');
        expect(isWalked()).toBe(false);
        expect(now()).toEqual(new Date(2026, 10, 2, 9, 30));

        localStorage.setItem('bb.clock-offset', String(-DAY));
        vi.resetModules();
        expect((await import('./clock')).isWalked()).toBe(false);
        localStorage.removeItem('bb.clock-offset');
    });
});
