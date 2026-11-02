import { openDB, type DBSchema, type IDBPDatabase } from 'idb';
import { computed, ref, shallowRef } from 'vue';
import { owner, parseReadKey } from './cache';

// The write outbox (OFF-006): every write the app makes, kept on the device in the
// order it was made until the server answers it. call.ts adds to it and replays it;
// screens read it to mark what is waiting (OFF-008, OFF-009) and to list it (OFF-010).
// Unlike the read cache it is one database for every user on the device: an expired
// session clears the owner cookie, and the entries must outlive that until their user
// signs in again. Another user's entries go only when that user confirms (OFF-012).

/** The server's refusal of an entry: it couldn't sync (OFF-011). */
export type Refusal = { status: number; body: unknown };

export interface OutboxEntry {
    /** The order it was made in, and its key. */
    seq: number;
    /** The bb_cache_owner of the user who made it (OFF-012). */
    user: string;
    name: string;
    input: Record<string, unknown>;
    /** Sent as Idempotency-Key, so a replay after a lost answer runs once (CAP-014). */
    idempotencyKey: string;
    /** The ULIDs of the rows it creates (CAP-019). */
    creates: string[];
    /** When the user acted, ISO 8601 on the walked clock (OFF-007). */
    occurredAt: string;
    /** What it does, in plain words: "Logged set 3 of Bench Press, 100 kg × 8" (OFF-010). */
    label: string;
    /** The reads it can change, from the catalog when it was made (CAP-011). */
    affects: string[];
    refusal?: Refusal;
}

/** Waiting to sync, waiting on an entry that couldn't sync, or couldn't sync (OFF-011). */
export type EntryState = 'waiting' | 'held' | 'failed';

const all = shallowRef<OutboxEntry[]>([]);
const user = ref<string | null>(null);

/** The signed-in user's entries, oldest first. */
export const entries = computed(() => all.value.filter((entry) => entry.user === user.value));

/** How many entries another user left on this device (OFF-012). */
export const othersWaiting = computed(() => all.value.filter((entry) => entry.user !== user.value).length);

// An entry waits behind one that couldn't sync when its input holds a ULID that entry,
// or one already waiting behind it, creates: the row it names doesn't exist yet.
const held = computed(() => {
    const blocked = new Set<string>();
    const seqs = new Set<number>();
    for (const entry of entries.value) {
        const waits = entry.refusal === undefined && mentions(entry.input, blocked);
        if (waits) seqs.add(entry.seq);
        if (waits || entry.refusal !== undefined) for (const id of entry.creates) blocked.add(id);
    }

    return seqs;
});

function mentions(value: unknown, ids: ReadonlySet<string>): boolean {
    if (ids.size === 0) return false;
    if (typeof value === 'string') return ids.has(value);
    if (value === null || typeof value !== 'object') return false;

    return Object.values(value).some((inner) => mentions(inner, ids));
}

export function stateOf(entry: OutboxEntry): EntryState {
    if (entry.refusal !== undefined) return 'failed';

    return held.value.has(entry.seq) ? 'held' : 'waiting';
}

/** The next entry to send: the oldest that neither failed nor waits on one that did. */
export function nextToSend(): OutboxEntry | undefined {
    user.value = owner();

    return entries.value.find((entry) => stateOf(entry) === 'waiting');
}

/**
 * OFF-008: whether a read's server-derived numbers wait on the outbox, because an entry
 * not yet answered lists it in its `affects`. Takes a read name or a read key (CAP-017).
 */
export function isPending(read: string): boolean {
    const name = parseReadKey(read)?.name ?? read;

    return entries.value.some((entry) => entry.refusal === undefined && (entry.affects.includes(name) || entry.affects.includes('*')));
}

/**
 * OFF-009: the user's own input for one row while it waits to sync, so the row can show
 * it as typed: the newest unanswered `name` entry whose input `matches`.
 */
export function pendingInput<T = Record<string, unknown>>(name: string, matches: (input: T) => boolean): T | undefined {
    const found = entries.value.filter((entry) => entry.name === name && entry.refusal === undefined && matches(entry.input as T)).at(-1);

    return found?.input as T | undefined;
}

// Storage. Memory is the source of truth, so the app keeps working where IndexedDB
// doesn't (a private window); there the outbox lasts as long as the page.

interface Schema extends DBSchema {
    entries: { key: number; value: OutboxEntry };
}

let db: Promise<IDBPDatabase<Schema>> | undefined;
// Removed this page load, so a stored copy whose delete hasn't landed yet never comes back.
const removed = new Set<number>();

function open(): Promise<IDBPDatabase<Schema>> {
    db ??= openDB<Schema>('bodybuilder-outbox', 1, {
        upgrade(database) {
            database.createObjectStore('entries', { keyPath: 'seq' });
        },
    }).catch((error: unknown) => {
        db = undefined;
        throw error;
    });

    return db;
}

/** Read the device's entries, and whose they are now (OFF-012). */
export async function loadOutbox(): Promise<void> {
    user.value = owner();
    const stored = await open()
        .then((database) => database.getAll('entries'))
        .catch(() => []);
    const bySeq = new Map(stored.filter((entry) => !removed.has(entry.seq)).map((entry) => [entry.seq, entry]));
    for (const entry of all.value) bySeq.set(entry.seq, entry);
    all.value = [...bySeq.values()].sort((a, b) => a.seq - b.seq);
}

/** Stores the entry before it resolves: what the user did is on the device. */
export async function addEntry(fields: Omit<OutboxEntry, 'seq'>): Promise<OutboxEntry> {
    const last = all.value.at(-1)?.seq ?? 0;
    // Microseconds since the epoch: unique and ordered across reloads.
    const entry = { ...fields, seq: Math.max(Date.now() * 1000, last + 1) };
    all.value = [...all.value, entry];
    await open()
        .then((database) => database.put('entries', entry))
        .catch(() => undefined);

    return entry;
}

export async function updateEntry(entry: OutboxEntry): Promise<void> {
    all.value = all.value.map((each) => (each.seq === entry.seq ? entry : each));
    await open()
        .then((database) => database.put('entries', entry))
        .catch(() => undefined);
}

export async function removeEntries(seqs: readonly number[]): Promise<void> {
    for (const seq of seqs) removed.add(seq);
    all.value = all.value.filter((entry) => !seqs.includes(entry.seq));
    await open()
        .then(async (database) => {
            const tx = database.transaction('entries', 'readwrite');
            await Promise.all([...seqs.map((seq) => tx.store.delete(seq)), tx.done]);
        })
        .catch(() => undefined);
}

/** OFF-012: delete what another user left unsynced, once the signed-in user has confirmed. */
export async function removeOthers(): Promise<void> {
    await removeEntries(all.value.filter((entry) => entry.user !== user.value).map((entry) => entry.seq));
}

// The reads each write affects, from the catalog's `affects:` tags (CAP-011). Kept in
// local storage, so an entry made offline still knows them.

const AFFECTS_KEY = 'bb.affects';

export function rememberAffects(byName: Record<string, string[]>): void {
    localStorage.setItem(AFFECTS_KEY, JSON.stringify(byName));
}

export function affectsOf(name: string): string[] {
    try {
        const byName = JSON.parse(localStorage.getItem(AFFECTS_KEY) ?? '{}') as Record<string, unknown>;
        const reads = byName[name];

        return Array.isArray(reads) ? reads.filter((read) => typeof read === 'string') : [];
    } catch {
        return [];
    }
}

// When the device last heard back from the server about everything it had (ACC-003).

const SYNCED_KEY = 'bb.syncedAt';

export const syncedAt = ref<number | null>(Number(localStorage.getItem(SYNCED_KEY)) || null);

export function markSynced(at = Date.now()): void {
    syncedAt.value = at;
    localStorage.setItem(SYNCED_KEY, String(at));
}

// Screens showing a read an entry's answer invalidated read it again (CAP-010).

const replayedListeners = new Set<(keys: readonly string[]) => void>();

export function onReplayed(listener: (keys: readonly string[]) => void): () => void {
    replayedListeners.add(listener);

    return () => replayedListeners.delete(listener);
}

export function announceReplayed(keys: readonly string[]): void {
    for (const listener of replayedListeners) listener(keys);
}
