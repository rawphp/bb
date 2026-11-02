<script setup lang="ts">
import type { ScanPhotoOutput } from '@/contracts/scan_photo';
import { toneIcons } from '@/design/toneIcons';
import { toneClasses } from '@/design/tones';
import ScanRowView from './ScanRowView.vue';

// FOOD-043, FOOD-045, FOOD-046: the foods of a photo or description as the server returned them, with the
// portion control and what to do with the list. Every number is the server's.
defineProps<{ draft: ScanPhotoOutput; disabled?: boolean; saved?: string | null }>();
defineEmits<{ portion: [portion: number]; grams: [index: number, grams: number]; match: [index: number]; remove: [index: number]; add: []; save: [] }>();
</script>

<template>
    <section class="space-y-3">
        <h2 class="font-semibold text-primary-text">Ingredients</h2>
        <div class="flex items-center gap-2 rounded-2xl bg-surface px-3.5 text-accent">
            <span class="flex-1 text-sm text-primary-text">Portion</span>
            <button
                type="button"
                :disabled="disabled || draft.portion_down == null"
                aria-label="Smaller portion"
                class="flex size-tap items-center justify-center active:opacity-70 disabled:opacity-40"
                data-test="portion-down"
                @click="draft.portion_down != null && $emit('portion', draft.portion_down)"
            >
                <svg viewBox="0 0 24 24" class="size-6" aria-hidden="true">
                    <circle cx="12" cy="12" r="10" class="fill-current" />
                    <path d="M7 12h10" class="stroke-surface" stroke-width="2" stroke-linecap="round" />
                </svg>
            </button>
            <span class="min-w-16 text-center font-semibold text-primary-text tabular-nums" :aria-label="`Portion ${draft.portion_text}`" data-test="portion">{{ draft.portion_text }}</span>
            <button
                type="button"
                :disabled="disabled || draft.portion_up == null"
                aria-label="Larger portion"
                class="flex size-tap items-center justify-center active:opacity-70 disabled:opacity-40"
                data-test="portion-up"
                @click="draft.portion_up != null && $emit('portion', draft.portion_up)"
            >
                <svg viewBox="0 0 24 24" class="size-6" aria-hidden="true">
                    <circle cx="12" cy="12" r="10" class="fill-current" />
                    <path d="M12 7v10M7 12h10" class="stroke-surface" stroke-width="2" stroke-linecap="round" />
                </svg>
            </button>
        </div>
        <ul class="space-y-3">
            <ScanRowView
                v-for="(row, index) in draft.rows"
                :key="`${index}-${row.name}`"
                :row="row"
                :disabled="disabled"
                @grams="$emit('grams', index, $event)"
                @match="$emit('match', index)"
                @remove="$emit('remove', index)"
            />
        </ul>
        <p v-if="draft.unmatched_note" class="flex gap-2 rounded-xl p-2.5 text-sm font-semibold" :class="toneClasses.warning">
            <svg viewBox="0 0 24 24" class="size-4 shrink-0 fill-current" aria-hidden="true"><path :d="toneIcons.warning" fill-rule="evenodd" /></svg>
            {{ draft.unmatched_note }}
        </p>
        <div data-test="totals">
            <p class="text-xs text-secondary-text">Total</p>
            <p class="text-sm font-semibold text-primary-text">{{ draft.totals.kcal }} · {{ draft.totals.protein }} · {{ draft.totals.fat }} · {{ draft.totals.carbs }}</p>
        </div>
        <button
            type="button"
            :disabled="disabled || draft.matched.length === 0"
            class="min-h-tap w-full rounded-full bg-accent font-semibold text-on-accent active:opacity-70 disabled:opacity-50"
            data-test="add-scanned"
            @click="$emit('add')"
        >
            Add to meal
        </button>
        <button
            type="button"
            :disabled="disabled || draft.matched.length === 0 || !!saved"
            class="min-h-tap w-full rounded-full bg-accent/15 font-semibold text-accent active:opacity-70 disabled:opacity-50"
            data-test="save-meal"
            @click="$emit('save')"
        >
            Save as saved meal
        </button>
        <p v-if="saved" class="flex items-center gap-2 text-sm font-semibold text-tone-good" role="status">
            <svg viewBox="0 0 24 24" class="size-4 shrink-0 fill-current" aria-hidden="true"><path :d="toneIcons.good" fill-rule="evenodd" /></svg>
            {{ saved }}
        </p>
    </section>
</template>
