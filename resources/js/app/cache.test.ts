import 'fake-indexeddb/auto';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { outputVersion as accountShowVersion } from '@/contracts/account_show';
import { cached, clearReads, drop, evict, MAX_AGE_MS, readKey, requestPersistentStorage, store, versionOf } from './cache';

const DAY = 24 * 60 * 60 * 1000;

describe('cache', () => {
    beforeEach(() => clearReads());

    afterEach(() => vi.unstubAllGlobals());

    it('CAP-017 builds the same read key as the server: name plus input with keys sorted at every depth', () => {
        expect(readKey('today_show', { date: '2026-11-01' })).toBe('today_show{"date":"2026-11-01"}');
        expect(readKey('account_show')).toBe('account_show{}');
        expect(readKey('meal_show', { meal: 2, filter: { z: true, a: [3, 1] }, date: '2026-11-01' })).toBe(
            'meal_show{"date":"2026-11-01","filter":{"a":[3,1],"z":true},"meal":2}',
        );
        expect(readKey('food_search', { q: 'crème/brûlée', grams: 100.0 })).toBe('food_search{"grams":100,"q":"crème/brûlée"}');
    });

    it('OFF-003 stores a read under its key with the time it was fetched', async () => {
        await store('today_show{}', { title: 'Today' }, 1_000);

        expect(await cached('today_show{}')).toEqual({ key: 'today_show{}', data: { title: 'Today' }, fetchedAt: 1_000, version: undefined });
        expect(await cached('meal_show{}')).toBeUndefined();
    });

    it("OFF-003 stores a read with its output schema version and treats a copy of another version as missing", async () => {
        expect(versionOf('account_show{}')).toBe(accountShowVersion);
        expect(versionOf('account_show{"id":1}')).toBe(accountShowVersion);

        await store('account_show{}', { name: 'Sam' }, 1_000);
        expect(await cached('account_show{}')).toEqual({ key: 'account_show{}', data: { name: 'Sam' }, fetchedAt: 1_000, version: accountShowVersion });

        await store('account_show{}', { fullName: 'Sam' }, 1_000, 'an-older-shape');
        expect(await cached('account_show{}')).toBeUndefined();
    });

    it('CAP-010 drops the reads a write invalidated and keeps the rest', async () => {
        await store('today_show{}', 1);
        await store('meal_show{}', 2);
        await store('week_show{}', 3);

        await drop(['today_show{}', 'meal_show{}', 'not_cached{}']);

        expect(await cached('today_show{}')).toBeUndefined();
        expect(await cached('meal_show{}')).toBeUndefined();
        expect(await cached('week_show{}')).toBeDefined();
    });

    it('OFF-005 drops cached reads that are not in the manifest and older than 30 days', async () => {
        const now = 100 * DAY;
        await store('old_kept{}', 1, now - MAX_AGE_MS - 1);
        await store('old_dropped{}', 2, now - MAX_AGE_MS - 1);
        await store('recent{}', 3, now - MAX_AGE_MS);

        await evict(new Set(['old_kept{}']), now);

        expect(await cached('old_kept{}')).toBeDefined();
        expect(await cached('old_dropped{}')).toBeUndefined();
        expect(await cached('recent{}')).toBeDefined();
    });

    it('OFF-005 asks for persistent storage once, and not when it is already granted', async () => {
        const persist = vi.fn(async () => true);
        const persisted = vi.fn(async () => false);
        vi.stubGlobal('navigator', { storage: { persist, persisted } });

        expect(await requestPersistentStorage()).toBe(true);
        expect(persist).toHaveBeenCalledTimes(1);

        persisted.mockResolvedValue(true);
        expect(await requestPersistentStorage()).toBe(true);
        expect(persist).toHaveBeenCalledTimes(1);
    });

    it('OFF-005 does without persistent storage where the browser has none', async () => {
        vi.stubGlobal('navigator', {});

        expect(await requestPersistentStorage()).toBe(false);
    });
});
