import 'fake-indexeddb/auto';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { outputVersion as accountShowVersion } from '@/contracts/account_show';
import { cached, clearReads, drop, evict, isCurrent, MAX_AGE_MS, OPEN_TIMEOUT_MS, parseReadKey, readKey, requestPersistentStorage, stamp, store, versionOf } from './cache';

const DAY = 24 * 60 * 60 * 1000;

// The bb_cache_owner cookie the server sets on sign-in (NameCacheOwner).
function signIn(owner: string | null): void {
    document.cookie = owner === null ? 'bb_cache_owner=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/' : `bb_cache_owner=${owner}; path=/`;
}

const readDatabases = async () =>
    (await indexedDB.databases())
        .map(({ name }) => name)
        .filter((name) => name?.startsWith('bodybuilder-reads-'))
        .sort();

describe('cache', () => {
    beforeEach(() => {
        signIn('a1');
        return clearReads();
    });

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

        expect(await cached('today_show{}')).toEqual({ key: 'today_show{}', data: { title: 'Today' }, fetchedAt: 1_000, version: versionOf('today_show{}') });
        expect(await cached('meal_show{}')).toBeUndefined();
    });

    it("OFF-003 stores a read with its output schema version and treats a copy of another version as missing", async () => {
        expect(versionOf('account_show{}')).toBe(accountShowVersion);
        expect(versionOf('account_show{"id":1}')).toBe(accountShowVersion);
        // A write with only an input schema has no output version.
        expect(versionOf('day_copy{}')).toBeUndefined();

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

    it("OFF-003 keeps each user's reads apart, and deletes the last user's when another signs in", async () => {
        await store('account_show{}', { name: 'A' });
        expect(await readDatabases()).toContain('bodybuilder-reads-a1');

        signIn('b2');
        expect(await cached('account_show{}')).toBeUndefined();
        await store('account_show{}', { name: 'B' });

        await vi.waitFor(async () => expect(await readDatabases()).toEqual(['bodybuilder-reads-b2']));
        expect((await cached('account_show{}'))?.data).toEqual({ name: 'B' });
    });

    it("OFF-003 caches nothing and keeps no one's reads while no one is signed in", async () => {
        await store('account_show{}', { name: 'A' });

        signIn(null);
        await store('today_show{}', { title: 'Today' });

        expect(await cached('account_show{}')).toBeUndefined();
        expect(await cached('today_show{}')).toBeUndefined();
        await vi.waitFor(async () => expect(await readDatabases()).toEqual([]));
    });

    it('OFF-003 an answer fetched for one user is not stored once another has signed in', () => {
        const since = stamp();
        expect(isCurrent('account_show{}', since)).toBe(true);

        signIn('b2');

        expect(isCurrent('account_show{}', since)).toBe(false);
        expect(isCurrent('account_show{}', stamp())).toBe(true);
    });

    it('CAP-017 parses a read key back into the name and input it was built from', () => {
        expect(parseReadKey(readKey('meal_show', { meal: 2, date: '2026-11-01' }))).toEqual({ name: 'meal_show', input: { date: '2026-11-01', meal: 2 } });
        expect(parseReadKey('account_show{}')).toEqual({ name: 'account_show', input: {} });
        for (const bad of ['week_show', '{}', 'x{bad', 'x[1]', 'x{}[', 'x"a"', 'x{"a":1}{']) expect(parseReadKey(bad), bad).toBeUndefined();
    });

    it("OFF-003 lets another tab delete this owner's database instead of blocking it", async () => {
        await store('account_show{}', { name: 'A' });

        const outcome = await new Promise<string>((resolve) => {
            const request = indexedDB.deleteDatabase('bodybuilder-reads-a1');
            request.onsuccess = () => resolve('deleted');
            request.onblocked = () => setTimeout(() => resolve('blocked'), 50);
        });

        expect(outcome).toBe('deleted');
        await store('account_show{}', { name: 'A again' });
        expect((await cached('account_show{}'))?.data).toEqual({ name: 'A again' });
    });

    it(`OFF-003 treats the cache as unavailable when its database does not open within ${OPEN_TIMEOUT_MS} ms`, async () => {
        // An older tab holds c3's database open and ignores the delete, so opening c3 queues behind it.
        const holder = await new Promise<IDBDatabase>((resolve) => {
            const request = indexedDB.open('bodybuilder-reads-c3', 1);
            request.onupgradeneeded = () => request.result.createObjectStore('reads', { keyPath: 'key' });
            request.onsuccess = () => resolve(request.result);
        });
        indexedDB.deleteDatabase('bodybuilder-reads-c3');
        signIn('c3');

        const started = Date.now();
        await expect(cached('account_show{}')).rejects.toThrow('did not open in time');
        expect(Date.now() - started).toBeLessThan(OPEN_TIMEOUT_MS + 500);

        holder.close();
        await vi.waitFor(async () => expect(await cached('account_show{}').catch(() => 'still blocked')).toBeUndefined());
    });

    it('OFF-003 still switches owners where the browser cannot list databases', async () => {
        vi.stubGlobal('indexedDB', Object.assign(Object.create(indexedDB), { databases: undefined }));

        signIn('d4');
        await store('account_show{}', { name: 'D' });

        expect((await cached('account_show{}'))?.data).toEqual({ name: 'D' });
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
