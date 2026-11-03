<script setup lang="ts">
import type { ReviewShowOutput } from '@/contracts/review_show';
import { toneIcons } from '@/design/toneIcons';
import { toneClasses } from '@/design/tones';

type WeighIns = NonNullable<ReviewShowOutput['weigh_ins']>;

// Step 1 (REV-003): the week's weigh-ins, a missed one in orange with "Missing · Add",
// which adds it late for its day, and "Add a weigh-in". The weigh-in sheet is the
// weigh-ins screens' own; `add` names the time it starts at, or none for now.
defineProps<{ weighIns: WeighIns }>();
const emit = defineEmits<{ add: [at?: string] }>();
</script>

<template>
    <div class="flex flex-col gap-2" data-test="step-weigh_ins">
        <p v-if="weighIns.empty_message" class="text-sm text-secondary-text">{{ weighIns.empty_message }}</p>
        <ul v-if="weighIns.rows.length" class="flex flex-col">
            <li v-for="row in weighIns.rows" :key="row.date" data-test="weigh-in" class="flex min-h-10 items-center justify-between gap-2">
                <span :class="row.is_missing ? 'text-tone-warning' : 'text-primary-text'">{{ row.date_label }}</span>
                <button
                    v-if="row.is_missing"
                    type="button"
                    data-test="add-missing"
                    class="flex min-h-tap items-center gap-1.5 rounded-full px-2.5 text-sm font-semibold active:opacity-70"
                    :class="toneClasses[weighIns.missing.tone]"
                    :aria-label="`Add the missing weigh-in for ${row.date_label}`"
                    @click="emit('add', row.add_at)"
                >
                    <svg aria-hidden="true" viewBox="0 0 24 24" class="size-4 shrink-0" fill="currentColor"><path :d="toneIcons[weighIns.missing.tone]" /></svg>
                    <span>{{ weighIns.missing.text }}</span>
                </button>
                <span v-else class="tabular-nums text-primary-text">{{ row.value_label }}</span>
            </li>
        </ul>
        <button type="button" data-test="add-weigh-in" class="flex min-h-tap items-center gap-2 self-start text-accent active:opacity-70" @click="emit('add')">
            <span aria-hidden="true" class="text-xl leading-none">+</span>
            <span>{{ weighIns.add_title }}</span>
        </button>
    </div>
</template>
