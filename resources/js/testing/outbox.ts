import { addEntry, entries, loadOutbox, removeEntries, removeOthers, syncedAt, updateEntry, type OutboxEntry } from '@/app/outbox';

// Puts entries in the real outbox for a component test, as the signed-in user a1.

export async function resetOutbox(): Promise<void> {
    document.cookie = 'bb_cache_owner=a1; path=/';
    await loadOutbox();
    await removeOthers();
    await removeEntries(entries.value.map((entry) => entry.seq));
    syncedAt.value = null;
}

export async function queue(fields: Partial<OutboxEntry> & { label: string }): Promise<OutboxEntry> {
    const entry = await addEntry({
        user: 'a1',
        name: 'steps_log',
        input: {},
        idempotencyKey: 'k',
        creates: [],
        occurredAt: '2026-11-02T09:30:00.000Z',
        affects: [],
        ...fields,
    });
    if (fields.refusal === undefined) return entry;
    await updateEntry({ ...entry, refusal: fields.refusal });

    return { ...entry, refusal: fields.refusal };
}
