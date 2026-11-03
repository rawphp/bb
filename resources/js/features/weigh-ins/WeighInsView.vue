<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { readFresh } from '@/app/call';
import ReadState from '@/app/ReadState.vue';
import { useRead } from '@/app/useRead';
import type { WeighInsShowOutput } from '@/contracts/weigh_ins_show';
import WeighInsScreen from './WeighInsScreen.vue';

// The Weigh-ins screen in More (WT-004), ported from rp-clone's WeighInsView.swift: it reads
// weigh_ins_show and hands it to WeighInsScreen. The server writes every label in the
// user's units, so a unit change shows at the next read (SET-001). `log` is set when
// /weigh-ins/log opened it (WT-002): a new weigh-in opens at once, at that time, or now
// when it is empty. `pending` marks the server's numbers as waiting to sync (OFF-008).
// The read is 90 days at a time: Show older reads the page before `next_before` and adds
// its rows after the ones shown. When the newest page reads again (a write changed it),
// each older page shown reads again, in order, so an edit there shows in place.
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

function readPage(before: string): Promise<WeighInsShowOutput> {
    return readFresh<WeighInsShowOutput>('weigh_ins_show', { before });
}

async function more(): Promise<void> {
    const before = shown.value?.next_before;
    if (!before || loadingMore.value) return;
    loadingMore.value = true;
    moreError.value = null;
    try {
        const page = await readPage(before);
        older.value = [...older.value, { before, page }];
    } catch {
        moreError.value = MORE_FAILED;
    } finally {
        loadingMore.value = false;
    }
}

// Each newest read starts a new round; an older round's answers are dropped.
let round = 0;
watch(data, async () => {
    if (older.value.length === 0) return;
    const mine = ++round;
    moreError.value = null;
    for (const { before } of older.value) {
        try {
            const page = await readPage(before);
            if (mine !== round) return;
            older.value = older.value.map((shownPage) => (shownPage.before === before ? { before, page } : shownPage));
        } catch {
            if (mine !== round) return;
            moreError.value = MORE_FAILED;
        }
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
