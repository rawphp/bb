<script setup lang="ts">
import type { ScanPhotoOutput } from '@/contracts/scan_photo';
import ScanGramsField from './ScanGramsField.vue';

// FOOD-043: one food of the scanned list: the AI's name, the food it is matched to, its grams, and Change or Remove.
defineProps<{ row: ScanPhotoOutput['rows'][number]; disabled?: boolean }>();
defineEmits<{ grams: [grams: number]; match: []; remove: [] }>();
</script>

<template>
    <li class="space-y-1.5 rounded-2xl bg-surface p-3.5" data-test="row">
        <div class="flex items-start gap-2">
            <div class="min-w-0 flex-1">
                <p class="font-semibold text-primary-text">{{ row.name }}</p>
                <p class="flex items-center gap-1.5 text-sm" :class="row.food_id ? 'text-secondary-text' : 'text-tone-warning'">
                    <svg viewBox="0 0 24 24" class="size-4 shrink-0 fill-none stroke-current stroke-2" stroke-linecap="round" aria-hidden="true">
                        <template v-if="row.food_id">
                            <path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1" />
                            <path d="M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1" />
                        </template>
                        <template v-else>
                            <circle cx="12" cy="12" r="9.5" />
                            <path d="M9.5 9.5a2.5 2.5 0 1 1 3.5 2.3c-.6.3-1 .8-1 1.5v.7M12 17h.01" />
                        </template>
                    </svg>
                    <span>{{ row.detail ? `${row.match_text} · ${row.detail}` : row.match_text }}</span>
                </p>
            </div>
            <button
                type="button"
                :disabled="disabled"
                :aria-label="`Remove ${row.name}`"
                class="-m-2 flex size-tap shrink-0 items-center justify-center text-secondary-text active:opacity-70 disabled:opacity-50"
                data-test="remove"
                @click="$emit('remove')"
            >
                <svg viewBox="0 0 24 24" class="size-6" aria-hidden="true">
                    <circle cx="12" cy="12" r="10" class="fill-current" />
                    <path d="m8.5 8.5 7 7m0-7-7 7" class="stroke-surface" stroke-width="2" stroke-linecap="round" />
                </svg>
            </button>
        </div>
        <div class="flex items-center gap-2">
            <ScanGramsField :grams="row.grams" :disabled="disabled" @commit="$emit('grams', $event)" />
            <span class="text-secondary-text">g</span>
            <span v-if="row.kcal_text" class="text-sm text-secondary-text">{{ row.kcal_text }}</span>
            <button
                type="button"
                :disabled="disabled"
                class="ml-auto min-h-tap text-sm font-semibold text-accent active:opacity-70 disabled:opacity-50"
                data-test="change-match"
                @click="$emit('match')"
            >
                {{ row.food_id ? 'Change' : 'Find a match' }}
            </button>
        </div>
    </li>
</template>
