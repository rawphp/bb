import { openDB, type DBSchema, type IDBPDatabase } from 'idb';

// The read cache (OFF-003): every read result in IndexedDB under its read key
// (CAP-017), with the time it was fetched. call.ts is its only user.

export const MAX_AGE_MS = 30 * 24 * 60 * 60 * 1000;

export type CachedRead = { key: string; data: unknown; fetchedAt: number };

interface Schema extends DBSchema {
    reads: { key: string; value: CachedRead };
}

let db: Promise<IDBPDatabase<Schema>> | undefined;

function open(): Promise<IDBPDatabase<Schema>> {
    db ??= openDB<Schema>('bodybuilder', 1, {
        upgrade(database) {
            database.createObjectStore('reads', { keyPath: 'key' });
        },
    });

    return db;
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

export async function cached(key: string): Promise<CachedRead | undefined> {
    return (await open()).get('reads', key);
}

export async function store(key: string, data: unknown, fetchedAt = Date.now()): Promise<void> {
    await (await open()).put('reads', { key, data, fetchedAt });
}

/** OFF-005: drop cached reads that are not in the current manifest and are older than 30 days. */
export async function evict(manifest: ReadonlySet<string>, now = Date.now()): Promise<void> {
    const tx = (await open()).transaction('reads', 'readwrite');

    for await (const cursor of tx.store) {
        if (!manifest.has(cursor.value.key) && now - cursor.value.fetchedAt > MAX_AGE_MS) {
            await cursor.delete();
        }
    }

    await tx.done;
}

export async function clearReads(): Promise<void> {
    await (await open()).clear('reads');
}

/** OFF-005: ask the browser not to evict the cache under storage pressure. */
export async function requestPersistentStorage(): Promise<boolean> {
    const storage = typeof navigator === 'undefined' ? undefined : navigator.storage;

    if (storage?.persist === undefined) return false;
    if (await storage.persisted?.()) return true;

    return storage.persist();
}
