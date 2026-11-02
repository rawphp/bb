<script setup lang="ts">
import { computed, ref } from 'vue';
import type { PlanShowOutput } from '@/contracts/plan_show';
import BottomSheet from '@/design/BottomSheet.vue';
import { toneClasses } from '@/design/tones';

// A deload week: what it runs at, and for a cut the switch for its diet break (PLAN-022,
// PLAN-023). Turning the break off asks first, with the server's warning; turning it back
// on needs no question. The server says which way the switch may go (diet_break_action);
// a week it may not change has no switch. Ported from rp-clone's DeloadWeekSheet.
const props = defineProps<{
    deload: PlanShowOutput['deloads'][number];
    /** plan_show's diet_break_off_warning. */
    warning: string;
    busy: boolean;
    error: string | null;
}>();

const emit = defineEmits<{ close: []; set: [dietBreak: boolean] }>();

const confirming = ref(false);
const on = computed(() => props.deload.diet_break_action === 'turn_off' && !confirming.value);

// The switch shows the server's state, or off while the user is asked; a tap never moves
// it on its own, so a refused write leaves it where the server has it.
function toggle(event: Event): void {
    const target = event.target as HTMLInputElement;
    const wanted = target.checked;
    target.checked = on.value;
    if (wanted) emit('set', true);
    else confirming.value = true;
}

function turnOff(): void {
    confirming.value = false;
    emit('set', false);
}
</script>

<template>
    <BottomSheet :open="true" :title="`Week ${deload.week + 1}`" save-title="Done" :heights="['half']" @cancel="emit('close')" @save="emit('close')">
        <div class="space-y-3.5 p-4" data-test="deload-sheet">
            <div>
                <p class="font-semibold">{{ deload.label }}</p>
                <p class="text-sm text-secondary-text">{{ deload.dates_text }}</p>
            </div>
            <template v-if="deload.diet_break_action !== 'none'">
                <label class="flex min-h-tap items-center gap-3">
                    <span class="flex-1">Maintenance-calorie diet break</span>
                    <input type="checkbox" role="switch" class="size-6 accent-accent" :checked="on" :disabled="busy" @change="toggle" />
                </label>
                <p class="text-[13px] text-secondary-text">A diet break holds your weight steady for the week so the next block starts fresh.</p>
            </template>
            <div v-if="confirming" role="alertdialog" aria-label="Deload in a deficit?" class="space-y-2 rounded-2xl p-3" :class="toneClasses.warning" data-test="diet-break-warning">
                <p class="font-semibold">Deload in a deficit?</p>
                <p class="text-sm">{{ warning }}</p>
                <button type="button" class="min-h-tap w-full rounded-xl bg-surface font-semibold text-tone-warning" data-test="turn-off-diet-break" @click="turnOff">
                    Turn off diet break
                </button>
                <button type="button" class="min-h-tap w-full rounded-xl font-semibold" data-test="keep-diet-break" @click="confirming = false">Keep diet break</button>
            </div>
            <p v-if="error" role="alert" class="rounded-xl p-3 text-sm" :class="toneClasses.warning">{{ error }}</p>
        </div>
    </BottomSheet>
</template>
