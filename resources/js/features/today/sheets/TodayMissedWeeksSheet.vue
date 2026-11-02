<script setup lang="ts">
import { computed, ref } from 'vue';
import ReadState from '@/app/ReadState.vue';
import { useRead } from '@/app/useRead';
import { useWrite } from '@/app/useWrite';
import type { MissedWeekResolveInput } from '@/contracts/missed_week_resolve';
import type { TodayShowOutput } from '@/contracts/today_show';
import BottomSheet from '@/design/BottomSheet.vue';
import { toneClasses } from '@/design/tones';

// Back after a week or more without training (SCH-058): restart the week, or end the
// mesocycle and build a new one. Ported from rp-clone's TodayMissedWeeksSheet; Today's
// read says whether to ask and words the question.
const props = defineProps<{ payload?: TodayShowOutput; date?: string }>();
const emit = defineEmits<{ close: [] }>();

const { data, failure, load } = useRead<TodayShowOutput>('today_show', props.date ? { date: props.date } : {}, 'Today could not be loaded.', props.payload);
const { send, busy, error } = useWrite(load);

const dismissed = ref(false);
const ending = ref(false);
const missed = computed(() => data.value?.missed_weeks ?? null);
// Asks while the read is on its way, and then only when weeks were missed.
const open = computed(() => !dismissed.value && (data.value === undefined || missed.value !== null));

function close(): void {
    if (dismissed.value) return;
    dismissed.value = true;
    emit('close');
}

async function resolve(choice: MissedWeekResolveInput['choice'], dietBreak?: boolean): Promise<void> {
    if (!missed.value) return;
    const input: MissedWeekResolveInput = { mesocycle_id: missed.value.mesocycle_id, choice, ...(dietBreak === undefined ? {} : { diet_break: dietBreak }) };
    if ((await send('missed_week_resolve', { ...input }, 'Your answer could not be saved.')) === 'done') close();
}

const primary = 'min-h-tap w-full rounded-full bg-accent px-4 font-semibold text-on-accent active:opacity-70 disabled:opacity-50';
const secondary = 'min-h-tap w-full rounded-full bg-accent/15 px-4 font-semibold text-accent active:opacity-70 disabled:opacity-50';
</script>

<template>
    <BottomSheet
        :open="open"
        :title="missed?.title ?? 'Welcome back'"
        cancel-title="Not now"
        :save-title="null"
        :heights="['half']"
        :cancel-disabled="busy"
        :dismissible="!busy"
        :error="error"
        @cancel="close"
    >
        <div v-if="missed" class="space-y-3 p-5" :aria-busy="busy || undefined">
            <p class="text-secondary-text">{{ missed.text }}</p>
            <template v-if="!ending">
                <button v-if="missed.can_restart" type="button" :class="primary" data-test="restart" :disabled="busy" @click="resolve('restart')">
                    Restart this week
                </button>
                <button type="button" :class="missed.can_restart ? secondary : primary" data-test="end" :disabled="busy" @click="ending = true">
                    End it and build a new one
                </button>
            </template>
            <template v-else>
                <p class="rounded-xl p-3 text-sm" :class="toneClasses.warning" data-test="end-warning">{{ missed.end_warning }}</p>
                <template v-if="missed.offers_diet_break">
                    <button type="button" :class="primary" data-test="end-with-break" :disabled="busy" @click="resolve('end', true)">
                        End and start a diet break next week
                    </button>
                    <button type="button" :class="secondary" data-test="end-without-break" :disabled="busy" @click="resolve('end', false)">
                        End without a diet break
                    </button>
                </template>
                <button v-else type="button" :class="primary" data-test="end-confirm" :disabled="busy" @click="resolve('end')">End the mesocycle</button>
                <button type="button" class="min-h-tap w-full font-semibold text-accent active:opacity-70" data-test="end-back" :disabled="busy" @click="ending = false">
                    Back
                </button>
            </template>
        </div>
        <div v-else class="p-4"><ReadState :failure="failure" what="Today" @retry="load" /></div>
    </BottomSheet>
</template>
