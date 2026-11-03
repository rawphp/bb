<script setup lang="ts">
import { computed, onUnmounted, ref, watch } from 'vue';
import { write } from '@/app/call';
import { writeFailureMessage } from '@/app/errors';
import { pendingInput } from '@/app/outbox';
import { ulid } from '@/app/ulid';
import { useRead } from '@/app/useRead';
import { useWrite } from '@/app/useWrite';
import type { ShoppingItemAddInput } from '@/contracts/shopping_item_add';
import type { ShoppingItemCheckInput } from '@/contracts/shopping_item_check';
import type { ShoppingItemDeleteInput } from '@/contracts/shopping_item_delete';
import type { ShoppingShowInput, ShoppingShowOutput } from '@/contracts/shopping_show';
import ShoppingRowView from './ShoppingRowView.vue';
import ShoppingStatus from './ShoppingStatus.vue';

// The shopping list (SHOP-001 to SHOP-005), ported from rp-clone's ShoppingListView.swift:
// the foods planned over a date range by store category, to tick off, with the user's own
// items and a share button. The server builds the list, sums the amounts and picks the
// default range. A tick goes through the outbox, so it works offline (OFF-030), and shows
// on its line until the server answers (OFF-009). `pending` marks the server's amounts as
// waiting to sync (OFF-008).
type Row = ShoppingShowOutput['sections'][number]['rows'][number];

const props = withDefaults(defineProps<{ payload?: ShoppingShowOutput; pending?: boolean }>(), { pending: false });

// The dates asked for. Empty until the user picks some, so the server's default range is
// read. A date change reads again through the same useRead. Every shopping write
// invalidates the list read with no dates (ShoppingReads::keys), so a replayed tick
// reloads this list whatever dates it shows.
const asked: ShoppingShowInput & Record<string, unknown> = {};
const { data, failure, load } = useRead<ShoppingShowOutput>('shopping_show', asked, 'Your shopping list could not be loaded.', props.payload);
const { send, busy, error } = useWrite(load);

const from = ref('');
const to = ref('');
watch(
    data,
    (list) => {
        if (!list) return;
        from.value = list.from;
        to.value = list.to;
    },
    { immediate: true },
);

// As rp-clone's pickers: a first day after the last moves the last day with it.
function pickDates(which: 'from' | 'to', value: string): void {
    if (value === '') return;
    if (which === 'from') {
        from.value = value;
        if (to.value < value) to.value = value;
    } else {
        to.value = value < from.value ? from.value : value;
    }
    asked.from = from.value;
    asked.to = to.value;
    void load().catch(() => undefined);
}

// The dates of the list on screen, so a write names the list it changes.
function shown(): { from: string; to: string } {
    return { from: data.value!.from, to: data.value!.to };
}

// OFF-009: the user's own tick for this line on this list while it waits to sync.
function waitingTick(row: Row): ShoppingItemCheckInput | undefined {
    const { from: first, to: last } = shown();
    return pendingInput<ShoppingItemCheckInput>('shopping_item_check', (input) => input.key === row.key && input.from === first && input.to === last);
}

function tickOf(row: Row): boolean {
    return waitingTick(row)?.ticked ?? row.is_ticked;
}

function tick(row: Row): void {
    const ticked = !tickOf(row);
    const input: ShoppingItemCheckInput = { ...shown(), key: row.key, ticked };
    const label = ticked ? `Ticked ${row.name} off the shopping list` : `Took the tick off ${row.name} on the shopping list`;
    error.value = null;
    write('shopping_item_check', { ...input }, { label }).catch((failed: unknown) => {
        error.value = writeFailureMessage(failed, 'The item could not be ticked. Try again.');
    });
}

// The user's own items (SHOP-005). The id is made here (CAP-019) and kept until the item
// is added, so a retry after no answer adds the same item.
const newItem = ref('');
const itemText = computed(() => newItem.value.trim());
let itemId = ulid();

async function add(): Promise<void> {
    if (itemText.value === '') return;
    const input: ShoppingItemAddInput = { id: itemId, text: itemText.value, ...shown() };
    if ((await send('shopping_item_add', { ...input }, 'The item could not be added. Try again.')) !== 'done') return;
    newItem.value = '';
    itemId = ulid();
}

function remove(row: Row): void {
    const input: ShoppingItemDeleteInput = { id: row.key.replace(/^manual:/, ''), ...shown() };
    void send('shopping_item_delete', { ...input }, 'The item could not be deleted. Try again.');
}

// Share hands the server's text to the share sheet, or copies it where there is none.
const copied = ref(false);
let copiedTimer: ReturnType<typeof setTimeout> | undefined;
onUnmounted(() => clearTimeout(copiedTimer));

async function share(): Promise<void> {
    const text = data.value?.share_text;
    if (!text) return;
    if (typeof navigator.share === 'function') {
        await navigator.share({ title: 'Shopping list', text }).catch(() => undefined);
        return;
    }
    await navigator.clipboard?.writeText(text).then(
        () => {
            copied.value = true;
            clearTimeout(copiedTimer);
            copiedTimer = setTimeout(() => (copied.value = false), 3000);
        },
        () => undefined,
    );
}
</script>

<template>
    <main class="bb-screen-content space-y-4">
        <div class="flex justify-end">
            <button
                type="button"
                aria-label="Share"
                class="flex size-11 items-center justify-center rounded-full bg-surface text-primary-text shadow-sm ring-1 ring-secondary-text/15 active:opacity-70 disabled:opacity-40"
                :disabled="!data?.share_text"
                @click="share"
            >
                <svg aria-hidden="true" viewBox="0 0 24 24" class="size-5.5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M12 15V3M8 7l4-4 4 4M8 11H6a1 1 0 0 0-1 1v8a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-8a1 1 0 0 0-1-1h-2" />
                </svg>
            </button>
        </div>
        <h1 class="text-3xl font-bold">Shopping list</h1>
        <p v-if="copied" role="status" class="px-1 text-sm text-secondary-text">Copied the list.</p>

        <section v-if="data" class="space-y-1.5" aria-labelledby="shopping-dates">
            <h2 id="shopping-dates" class="px-4 text-[0.9375rem] font-medium text-secondary-text">Dates</h2>
            <div class="divide-y divide-secondary-text/15 rounded-2xl bg-surface px-4">
                <label class="flex min-h-tap items-center justify-between gap-3 py-2">
                    <span class="text-primary-text">From</span>
                    <input
                        name="from"
                        type="date"
                        :value="from"
                        class="rounded-lg bg-secondary-text/10 px-3 py-1.5 text-primary-text"
                        @change="pickDates('from', ($event.target as HTMLInputElement).value)"
                    />
                </label>
                <label class="flex min-h-tap items-center justify-between gap-3 py-2">
                    <span class="text-primary-text">To</span>
                    <input
                        name="to"
                        type="date"
                        :value="to"
                        :min="from"
                        class="rounded-lg bg-secondary-text/10 px-3 py-1.5 text-primary-text"
                        @change="pickDates('to', ($event.target as HTMLInputElement).value)"
                    />
                </label>
            </div>
        </section>

        <ShoppingStatus
            :data="data"
            :failure="failure"
            :error="error"
            @retry="load"
        />

        <template v-if="data">
            <section v-for="section in data.sections" :key="section.category" class="space-y-1.5">
                <h2 data-test="section-title" class="px-4 text-[0.9375rem] font-medium text-secondary-text">{{ section.title }}</h2>
                <ul class="divide-y divide-secondary-text/15 overflow-hidden rounded-2xl bg-surface">
                    <ShoppingRowView
                        v-for="row in section.rows"
                        :key="row.key"
                        :row="row"
                        :ticked="tickOf(row)"
                        :tick-pending="waitingTick(row) !== undefined"
                        :pending="pending"
                        :disabled="busy"
                        @tick="tick(row)"
                        @delete="remove(row)"
                    />
                </ul>
            </section>

            <section class="space-y-1.5" aria-labelledby="shopping-add">
                <h2 id="shopping-add" class="px-4 text-[0.9375rem] font-medium text-secondary-text">Add an item</h2>
                <form data-test="add-item" class="flex min-h-tap items-center gap-2 rounded-2xl bg-surface py-1 pr-1 pl-4" @submit.prevent="add">
                    <input
                        v-model="newItem"
                        name="shopping-item"
                        type="text"
                        placeholder="Item"
                        aria-label="Item"
                        autocomplete="off"
                        enterkeyhint="done"
                        :disabled="busy"
                        class="min-h-tap min-w-0 flex-1 bg-transparent text-base text-primary-text placeholder:text-secondary-text"
                    />
                    <button
                        type="submit"
                        aria-label="Add item"
                        class="flex min-h-tap min-w-tap items-center justify-center text-accent active:opacity-70 disabled:opacity-40"
                        :disabled="itemText === '' || busy"
                    >
                        <svg aria-hidden="true" viewBox="0 0 24 24" class="size-7">
                            <circle cx="12" cy="12" r="10" fill="currentColor" />
                            <path d="M12 7.5v9M7.5 12h9" stroke="var(--color-on-accent)" stroke-width="2.2" stroke-linecap="round" />
                        </svg>
                    </button>
                </form>
            </section>
        </template>
    </main>
</template>
