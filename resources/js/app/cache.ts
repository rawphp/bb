import { openDB, type DBSchema, type IDBPDatabase } from 'idb';

// The read cache (OFF-003): every read result in IndexedDB under its read key
// (CAP-017), with the time it was fetched. call.ts is its only user.
// Each user has their own database, named by the bb_cache_owner cookie the server
// sets on sign-in and clears on sign-out (NameCacheOwner). With no owner there is
// no cache, and a change of owner deletes every other owner's database, so the
// cookie going away (a 401, a sign-out) leaves no one's reads on the device.

export const MAX_AGE_MS = 30 * 24 * 60 * 60 * 1000;

export type CachedRead = { key: string; data: unknown; fetchedAt: number; version?: string };

// Each read's output schema version, from its generated contract. A copy cached
// under another version was written for an older output shape and counts as missing.
// A write with no output schema has no outputVersion, so each module is read whole.
const contracts = import.meta.glob<{ outputVersion?: string }>('../contracts/*.ts', { eager: true });

export function versionOf(key: string): string | undefined {
    const brace = key.indexOf('{');

    return contracts[`../contracts/${brace < 0 ? key : key.slice(0, brace)}.ts`]?.outputVersion;
}

interface Schema extends DBSchema {
    reads: { key: string; value: CachedRead };
}

const OWNER_COOKIE = 'bb_cache_owner';
const DB_PREFIX = 'bodybuilder-reads-';

let opened: { owner: string | null; db: Promise<IDBPDatabase<Schema>> | undefined } | undefined;

function owner(): string | null {
    const match = document.cookie.match(new RegExp(`(?:^|; )${OWNER_COOKIE}=([0-9a-f]+)`));

    return match?.[1] ?? null;
}

/** The current owner's database, or undefined when no one is signed in on this device. */
function open(): Promise<IDBPDatabase<Schema>> | undefined {
    const current = owner();
    sync(current);
    if (opened !== undefined && opened.owner === current) return opened.db;

    // Close the old owner's connection, or deleting that database waits on it forever.
    void opened?.db?.then((db) => db.close());
    void deleteOtherOwners(current);
    opened = {
        owner: current,
        db:
            current === null
                ? undefined
                : openDB<Schema>(DB_PREFIX + current, 1, {
                      upgrade(database) {
                          database.createObjectStore('reads', { keyPath: 'key' });
                      },
                  }),
    };

    return opened.db;
}

async function deleteOtherOwners(current: string | null): Promise<void> {
    const databases = await indexedDB.databases().catch(() => []);

    for (const { name } of databases) {
        if (name?.startsWith(DB_PREFIX) && name !== DB_PREFIX + current) indexedDB.deleteDatabase(name);
    }
}

/** The capability name plus its input as JSON with keys sorted at every depth, as the server's ReadKey builds it. */
export function readKey(name: string, input: Record<string, unknown> = {}): string {
    return name + JSON.stringify(sorted(input));
}

function sorted(value: unknown): unknown {
    if (Array.isArray(value)) return value.map(sorted);
    if (value === null || typeof value !== 'object') return value;

    return Object.fromEntries(
        Object.keys(value)
            .sort()
            .map((key) => [key, sorted((value as Record<string, unknown>)[key])]),
    );
}

// Bumped by every drop and clear. A fetch takes a stamp() before it starts and stores
// its answer only if isCurrent(), so a request answered before a write or a sign-out
// can't put the data that write or sign-out removed back.
// A change of owner counts as a clear, so an answer fetched for one user is never
// stored for the next.
let generation = 0;
let clearedAt = 0;
const droppedAt = new Map<string, number>();
let lastOwner: string | null | undefined;

function sync(current: string | null): void {
    if (lastOwner !== undefined && current !== lastOwner) {
        clearedAt = ++generation;
        droppedAt.clear();
    }
    lastOwner = current;
}

export function stamp(): number {
    sync(owner());

    return generation;
}

export function isCurrent(key: string, since: number): boolean {
    sync(owner());

    return clearedAt <= since && (droppedAt.get(key) ?? 0) <= since;
}

export async function cached(key: string): Promise<CachedRead | undefined> {
    const hit = await open()?.then((db) => db.get('reads', key));

    return hit?.version === versionOf(key) ? hit : undefined;
}

export async function store(key: string, data: unknown, fetchedAt = Date.now(), version = versionOf(key)): Promise<void> {
    await open()?.then((db) => db.put('reads', { key, data, fetchedAt, version }));
}

export async function drop(keys: readonly string[]): Promise<void> {
    generation++;
    for (const key of keys) droppedAt.set(key, generation);
    const db = await open();
    if (db === undefined) return;
    const tx = db.transaction('reads', 'readwrite');
    await Promise.all([...keys.map((key) => tx.store.delete(key)), tx.done]);
}

/** OFF-005: drop cached reads that are not in the current manifest and are older than 30 days. */
export async function evict(manifest: ReadonlySet<string>, now = Date.now()): Promise<void> {
    const db = await open();
    if (db === undefined) return;
    const tx = db.transaction('reads', 'readwrite');

    for await (const cursor of tx.store) {
        if (!manifest.has(cursor.value.key) && now - cursor.value.fetchedAt > MAX_AGE_MS) {
            await cursor.delete();
        }
    }

    await tx.done;
}

export async function clearReads(): Promise<void> {
    clearedAt = ++generation;
    droppedAt.clear();
    await open()?.then((db) => db.clear('reads'));
}

/** OFF-005: ask the browser not to evict the cache under storage pressure. */
export async function requestPersistentStorage(): Promise<boolean> {
    const storage = typeof navigator === 'undefined' ? undefined : navigator.storage;

    if (storage?.persist === undefined) return false;
    if (await storage.persisted?.()) return true;

    return storage.persist();
}
