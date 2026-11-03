<script setup lang="ts">
import ReadState from '@/app/ReadState.vue';
import { useRead } from '@/app/useRead';
import type { WeighInsShowOutput } from '@/contracts/weigh_ins_show';
import WeighInsScreen from './WeighInsScreen.vue';

// The Weigh-ins screen in More (WT-004), ported from rp-clone's WeighInsView.swift: it reads
// weigh_ins_show and hands it to WeighInsScreen. The server writes every label in the
// user's units, so a unit change shows at the next read (SET-001). `log` is set when
// /weigh-ins/log opened it (WT-002): a new weigh-in opens at once, at that time, or now
// when it is empty. `pending` marks the server's numbers as waiting to sync (OFF-008).
const props = withDefaults(defineProps<{ payload?: WeighInsShowOutput; pending?: boolean; log?: string }>(), { pending: false, log: undefined });

const { data, failure, load } = useRead<WeighInsShowOutput>('weigh_ins_show', {}, 'Your weigh-ins could not be loaded.', props.payload);
</script>

<template>
    <WeighInsScreen v-if="data" :data="data" :pending="pending" :log="log" :reload="load" />
    <main v-else class="bb-screen-content space-y-4">
        <h1 class="text-3xl font-bold">Weigh-ins</h1>
        <ReadState :failure="failure" what="Your weigh-ins" @retry="load" />
    </main>
</template>
