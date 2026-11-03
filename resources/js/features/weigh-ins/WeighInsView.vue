<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { read, readFresh } from '@/app/call';
import ReadState from '@/app/ReadState.vue';
import { useRead } from '@/app/useRead';
import type { WeighInsShowOutput } from '@/contracts/weigh_ins_show';
import WeighInsScreen from './WeighInsScreen.vue';

// The Weigh-ins screen in More (WT-004), ported from rp-clone's WeighInsView.swift: it reads
// weigh_ins_show and hands it to WeighInsScreen. The server writes every label in the
// user's units, so a unit change shows at the next read (SET-001). `log` is set when
// /weigh-ins/log opened it (WT-002): a new weigh-in opens at once, at that time, or now
// when it is empty. `pending` marks the server's numbers as waiting to sync (OFF-008).
// The read is 90 days at a time: Show older reads the page before `next_before` (a cached
// copy at once, then the server's, OFF-003) and adds its rows after the ones shown. When
// the newest page reads again (a write changed it, or the day moved on), the older pages
// shown read again from the server as a chain, each from the `next_before` of the one
// above it, so an edit there shows in place and no day falls between two pages.
const props = withDefaults(defineProps<{ payload?: WeighInsShowOutput; pending?: boolean; log?: string }>(), { pending: false, log: undefined });

const MORE_FAILED = 'Older weigh-ins could not be loaded.';

const { data, failure, load } = useRead<WeighInsShowOutput>('weigh_ins_show', {}, 'Your weigh-ins could not be loaded.', props.payload);

/** The older pages shown, oldest last, each under the `before` it was read with. */
const older = ref<{ before: string; page: WeighInsShowOutput }[]>([]);
const loadingMore = ref(false);
const moreError = ref<string | null>(null);

const shown = computed<WeighInsShowOutput | undefined>(() => {
    const newest = data.value;
    if (newest === undefined || older.value.length === 0) return newest;
    const pages = older.value.map(({ page }) => page);

    return {
        ...newest,
        rows: [...newest.rows, ...pages.flatMap((page) => page.rows)],
        steps: { ...newest.steps, rows: [...newest.steps.rows, ...pages.flatMap((page) => page.steps.rows)] },
        next_before: pages[pages.length - 1]!.next_before ?? null,
    };
});

function replacePage(index: number, before: string, page: WeighInsShowOutput): void {
    older.value = older.value.map((shownPage, at) => (at === index ? { before, page } : shownPage));
}

async function more(): Promise<void> {
    const before = shown.value?.next_before;
    if (!before || loadingMore.value) return;
    loadingMore.value = true;
    moreError.value = null;
    const index = older.value.length;
    try {
        const page = await read<WeighInsShowOutput>('weigh_ins_show', { before }, (fresh) => {
            if (older.value[index]?.before === before) replacePage(index, before, fresh);
        });
        older.value = [...older.value, { before, page }];
    } catch {
        moreError.value = MORE_FAILED;
    } finally {
        loadingMore.value = false;
    }
}

// Each newest read starts a new round; an older round's answers are dropped. A page that
// fails keeps its old rows, and the chain goes on from them.
let round = 0;
watch(data, async (newest) => {
    if (newest === undefined || older.value.length === 0) return;
    const mine = ++round;
    moreError.value = null;
    let before = newest.next_before ?? null;
    for (let index = 0; index < older.value.length; index++) {
        if (before === null) {
            older.value = older.value.slice(0, index);
            return;
        }
        try {
            const page = await readFresh<WeighInsShowOutput>('weigh_ins_show', { before });
            if (mine !== round) return;
            replacePage(index, before, page);
        } catch {
            if (mine !== round) return;
            moreError.value = MORE_FAILED;
        }
        before = older.value[index]!.page.next_before ?? null;
    }
});
</script>

<template>
    <WeighInsScreen
        v-if="shown"
        :data="shown"
        :pending="pending"
        :log="log"
        :reload="load"
        :loading-more="loadingMore"
        :more-error="moreError"
        @more="more"
    />
    <main v-else class="bb-screen-content space-y-4">
        <h1 class="text-3xl font-bold">Weigh-ins</h1>
        <ReadState :failure="failure" what="Your weigh-ins" @retry="load" />
    </main>
</template>
