<script setup lang="ts">
import { computed, ref } from 'vue';
import type { PhaseLengthSetInput } from '@/contracts/phase_length_set';
import type { PhaseShowOutput } from '@/contracts/phase_show';
import BottomSheet from '@/design/BottomSheet.vue';
import ChoiceList, { type Choice } from '@/design/ChoiceList.vue';
import { toneClasses } from '@/design/tones';

type Length = PhaseShowOutput['lengths'][number];

// PH-006, PLAN-021: the lengths phase_show allows (TBL-010), each with its end date. A
// length that ends part-way through a mesocycle lists the server's fixes, and the user
// saves by picking one; any other length saves as it is. Warnings show and never block.
// `weeks` opens it on that length (PLAN-028's event week), else on the phase's own.
// Ported from rp-clone's AdjustLengthSheet.
const props = defineProps<{ lengths: Length[]; weeks?: number | null; busy: boolean; error: string | null }>();

const emit = defineEmits<{ close: []; apply: [weeks: PhaseLengthSetInput['weeks'], fix: PhaseLengthSetInput['fix']] }>();

const opening = props.lengths.findIndex((length) => (props.weeks === undefined ? length.is_selected : length.weeks === props.weeks));
const picked = ref<number | null>(opening >= 0 ? opening : null);
const length = computed<Length | null>(() => (picked.value === null ? null : (props.lengths[picked.value] ?? null)));

const choices = computed<Choice<number>[]>(() => props.lengths.map((option, index) => ({ id: index, title: option.label, detail: option.ends_text })));
</script>

<template>
    <BottomSheet
        :open="true"
        title="Adjust length"
        :save-title="length?.fixes.length ? null : 'Save'"
        :save-disabled="!length || length.is_selected || busy"
        :cancel-disabled="busy"
        :dismissible="!busy"
        @cancel="emit('close')"
        @save="length && emit('apply', length.weeks ?? null, 'keep_end')"
    >
        <div class="space-y-4 p-4" data-test="length-sheet">
            <ChoiceList v-model="picked" label="Length" :choices="choices" :disabled="busy" />

            <template v-if="length">
                <p v-for="warning in length.warnings" :key="warning.text" class="rounded-xl p-3 text-[13px]" :class="toneClasses[warning.tone]">{{ warning.text }}</p>

                <section v-if="length.mesocycles.length">
                    <h2 class="px-1 pb-1.5 text-sm font-semibold text-secondary-text">Mesocycles</h2>
                    <ul class="divide-y divide-secondary-text/15 rounded-2xl bg-surface">
                        <li v-for="meso in length.mesocycles" :key="meso.label" class="flex items-baseline gap-2 px-4 py-2.5">
                            <span class="flex-1">{{ meso.label }}</span>
                            <span class="text-sm text-secondary-text">{{ meso.weeks_text }} · {{ meso.dates_text }}</span>
                        </li>
                    </ul>
                </section>

                <section v-if="length.fixes.length">
                    <h2 class="px-1 pb-1.5 text-sm font-semibold text-secondary-text">This ends part-way through a mesocycle</h2>
                    <div class="divide-y divide-secondary-text/15 overflow-hidden rounded-2xl bg-surface">
                        <button
                            v-for="fix in length.fixes"
                            :key="fix.fix"
                            type="button"
                            class="flex min-h-tap w-full items-center px-4 py-2.5 text-left text-accent disabled:text-secondary-text"
                            :disabled="busy"
                            data-test="fix"
                            @click="emit('apply', length.weeks ?? null, fix.fix)"
                        >
                            {{ fix.text }}
                        </button>
                    </div>
                </section>
            </template>

            <p v-if="error" role="alert" class="rounded-xl p-3 text-sm" :class="toneClasses.warning">{{ error }}</p>
        </div>
    </BottomSheet>
</template>
