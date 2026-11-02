<script setup lang="ts">
import { ref } from 'vue';
import { CapabilityError, discardEntry, retryEntry } from '@/app/call';
import { failureMessage } from '@/app/errors';
import { entries, stateOf, type OutboxEntry } from '@/app/outbox';
import PendingMark from '@/design/PendingMark.vue';
import { toneClasses } from '@/design/tones';

// The outbox (OFF-010, OFF-011): each change waiting to sync, in plain words, when it was
// made and its state. A change the server refused shows the server's words (CAP-012). The
// user fixes the fields the server named and sends it again, or discards it. The script
// holds which entry is being edited or discarded and what is typed, nothing more.

const made = new Intl.DateTimeFormat(undefined, { weekday: 'short', day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit' });

const editing = ref<number | null>(null);
const typed = ref<Record<string, string>>({});
const discarding = ref<number | null>(null);

function message(entry: OutboxEntry): string {
    const refused = new CapabilityError(entry.name, entry.refusal?.status ?? 0, entry.refusal?.body ?? null);

    return failureMessage(refused, "The server couldn't save this change.");
}

/** The fields the server named that the user typed: a text or a number in the input. */
function fixable(entry: OutboxEntry): { field: string; message: string }[] {
    const violations = (entry.refusal?.body as { error?: { violations?: unknown } } | null)?.error?.violations;
    if (!Array.isArray(violations)) return [];

    return violations.filter(
        (violation): violation is { field: string; message: string } =>
            typeof violation?.field === 'string' && ['string', 'number'].includes(typeof entry.input[violation.field]),
    );
}

function edit(entry: OutboxEntry): void {
    editing.value = entry.seq;
    discarding.value = null;
    typed.value = Object.fromEntries(fixable(entry).map(({ field }) => [field, String(entry.input[field])]));
}

// A number field goes back as a number when what is typed reads as one; anything else
// goes as typed, and the server says what is wrong with it.
function fixed(entry: OutboxEntry): Record<string, unknown> {
    const input = { ...entry.input };
    for (const [field, text] of Object.entries(typed.value)) {
        const number = Number(text);
        input[field] = typeof entry.input[field] === 'number' && text.trim() !== '' && !Number.isNaN(number) ? number : text;
    }

    return input;
}

function send(entry: OutboxEntry, input = entry.input): void {
    editing.value = null;
    void retryEntry(entry, input);
}

function discard(entry: OutboxEntry): void {
    discarding.value = null;
    void discardEntry(entry);
}

function words(field: string): string {
    const text = field.replaceAll('_', ' ');

    return text.charAt(0).toUpperCase() + text.slice(1);
}
</script>

<template>
    <main class="bb-screen-content space-y-4">
        <h1 class="text-center text-lg font-semibold">Sync</h1>

        <p v-if="entries.length === 0" class="rounded-2xl bg-surface p-4 text-secondary-text">Nothing is waiting to sync.</p>

        <ul v-else class="divide-y divide-secondary-text/20 overflow-hidden rounded-2xl bg-surface">
            <li v-for="entry in entries" :key="entry.seq" class="space-y-2 p-4" :data-entry="entry.seq">
                <p class="font-medium">{{ entry.label }}</p>
                <p class="text-sm text-secondary-text">
                    <time :datetime="entry.occurredAt">{{ made.format(new Date(entry.occurredAt)) }}</time>
                </p>

                <PendingMark v-if="stateOf(entry) === 'waiting'" />
                <p v-else-if="stateOf(entry) === 'held'" class="text-sm text-secondary-text">Waiting on a change that couldn't sync</p>
                <template v-else>
                    <p class="text-sm font-semibold text-tone-warning">Couldn't sync</p>
                    <p class="rounded-xl p-3 text-sm" :class="toneClasses.warning" role="alert">{{ message(entry) }}</p>

                    <form v-if="editing === entry.seq" class="space-y-2" @submit.prevent="send(entry, fixed(entry))">
                        <label v-for="{ field, message: why } in fixable(entry)" :key="field" class="block space-y-1">
                            <span class="text-sm font-medium">{{ words(field) }}</span>
                            <input v-model="typed[field]" :name="field" class="min-h-tap w-full rounded-xl bg-background px-3" autocomplete="off" />
                            <span class="block text-sm text-secondary-text">{{ why }}</span>
                        </label>
                        <div class="flex gap-2">
                            <button type="button" class="min-h-tap flex-1 rounded-xl border border-secondary-text/25 px-4 active:opacity-70" @click="editing = null">Cancel</button>
                            <button type="submit" class="min-h-tap flex-1 rounded-xl bg-accent px-4 font-semibold text-on-accent active:opacity-70">Save and sync</button>
                        </div>
                    </form>

                    <div v-else-if="discarding === entry.seq" class="space-y-2">
                        <p class="text-sm">Discard this change? It will not be saved.</p>
                        <div class="flex gap-2">
                            <button type="button" class="min-h-tap flex-1 rounded-xl border border-secondary-text/25 px-4 active:opacity-70" data-test="keep" @click="discarding = null">Keep</button>
                            <button type="button" class="min-h-tap flex-1 rounded-xl px-4 font-semibold active:opacity-70" :class="toneClasses.warning" data-test="confirm-discard" @click="discard(entry)">
                                Discard
                            </button>
                        </div>
                    </div>

                    <div v-else class="flex flex-wrap gap-x-4">
                        <button v-if="fixable(entry).length > 0" type="button" class="min-h-tap text-accent active:opacity-70" data-test="edit" @click="edit(entry)">Edit</button>
                        <button v-else type="button" class="min-h-tap text-accent active:opacity-70" data-test="retry" @click="send(entry)">Try again</button>
                        <button type="button" class="min-h-tap text-tone-warning active:opacity-70" data-test="discard" @click="(discarding = entry.seq), (editing = null)">Discard</button>
                    </div>
                </template>
            </li>
        </ul>
    </main>
</template>
