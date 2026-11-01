import { openDB, type DBSchema, type IDBPDatabase } from 'idb';

// The read cache (OFF-003): every read result in IndexedDB under its read key
// (CAP-017), with the time it was fetched. call.ts is its only user.

export const MAX_AGE_MS = 30 * 24 * 60 * 60 * 1000;

export type CachedRead = { key: string; data: unknown; fetchedAt: number; version?: string };

// Each read's output schema version, from its generated contract. A copy cached
// under another version was written for an older output shape and counts as missing.
const versions = import.meta.glob<string>('../contracts/*.ts', { eager: true, import: 'outputVersion' });

export function versionOf(key: string): string | undefined {
    const brace = key.indexOf('{');

    return versions[`../contracts/${brace < 0 ? key : key.slice(0, brace)}.ts`];
}

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

// Bumped by every drop and clear. A fetch takes a stamp() before it starts and stores
// its answer only if isCurrent(), so a request answered before a write or a sign-out
// can't put the data that write or sign-out removed back.
let generation = 0;
let clearedAt = 0;
const droppedAt = new Map<string, number>();

export function stamp(): number {
    return generation;
}

export function isCurrent(key: string, since: number): boolean {
    return clearedAt <= since && (droppedAt.get(key) ?? 0) <= since;
}

export async function cached(key: string): Promise<CachedRead | undefined> {
    const hit = await (await open()).get('reads', key);

    return hit?.version === versionOf(key) ? hit : undefined;
}

export async function store(key: string, data: unknown, fetchedAt = Date.now(), version = versionOf(key)): Promise<void> {
    await (await open()).put('reads', { key, data, fetchedAt, version });
}

export async function drop(keys: readonly string[]): Promise<void> {
    generation++;
    for (const key of keys) droppedAt.set(key, generation);
    const tx = (await open()).transaction('reads', 'readwrite');
    await Promise.all([...keys.map((key) => tx.store.delete(key)), tx.done]);
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
    clearedAt = ++generation;
    droppedAt.clear();
    await (await open()).clear('reads');
}

/** OFF-005: ask the browser not to evict the cache under storage pressure. */
export async function requestPersistentStorage(): Promise<boolean> {
    const storage = typeof navigator === 'undefined' ? undefined : navigator.storage;

    if (storage?.persist === undefined) return false;
    if (await storage.persisted?.()) return true;

    return storage.persist();
}
