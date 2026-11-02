<script setup lang="ts">
import { computed, ref } from 'vue';
import ReadState from '@/app/ReadState.vue';
import { useRead } from '@/app/useRead';
import { useWrite } from '@/app/useWrite';
import type { MissedSessionResolveInput } from '@/contracts/missed_session_resolve';
import type { TodayShowOutput } from '@/contracts/today_show';
import BottomSheet from '@/design/BottomSheet.vue';

// The morning after a missed training day (SCH-057): what to do with the session. Ported
// from rp-clone's TodayMissedDaySheet; Today's read offers the choices and the day each moves to.
const props = defineProps<{ payload?: TodayShowOutput; date?: string }>();
const emit = defineEmits<{ close: [] }>();

const { data, failure, load } = useRead<TodayShowOutput>('today_show', props.date ? { date: props.date } : {}, 'Today could not be loaded.', props.payload);
const { send, busy, error } = useWrite(load);

type Missed = NonNullable<TodayShowOutput['missed_session']>;

const dismissed = ref(false);
const missed = computed(() => data.value?.missed_session ?? null);
// Asks while the read is on its way, and then only when a session was missed.
const open = computed(() => !dismissed.value && (data.value === undefined || missed.value !== null));

function close(): void {
    dismissed.value = true;
    emit('close');
}

async function choose(session: Missed, choice: Missed['choices'][number]): Promise<void> {
    const input: MissedSessionResolveInput = { mesocycle_id: session.mesocycle_id, week: session.week, day: session.day, choice: choice.choice, date: choice.date ?? null };
    if ((await send('missed_session_resolve', { ...input }, 'Your choice could not be saved.')) === 'done') close();
}
</script>

<template>
    <BottomSheet
        :open="open"
        :title="missed?.title ?? 'Missed session'"
        cancel-title="Not now"
        :save-title="null"
        :heights="['half']"
        :cancel-disabled="busy"
        :error="error"
        @cancel="close"
    >
        <div v-if="missed" class="space-y-3 p-5" :aria-busy="busy || undefined">
            <p class="text-secondary-text">{{ missed.text }}</p>
            <button
                v-for="choice in missed.choices"
                :key="choice.choice"
                type="button"
                data-test="choice"
                class="min-h-tap w-full rounded-full px-4 font-semibold active:opacity-70 disabled:opacity-50"
                :class="choice.choice === 'skip' ? 'bg-accent/15 text-accent' : 'bg-accent text-on-accent'"
                :disabled="busy"
                @click="choose(missed, choice)"
            >
                {{ choice.label }}
            </button>
        </div>
        <div v-else class="p-4"><ReadState :failure="failure" what="Today" @retry="load" /></div>
    </BottomSheet>
</template>
