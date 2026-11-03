<script setup lang="ts">
import type { TodayShowOutput } from '@/contracts/today_show';
import { toneClasses } from '@/design/tones';

type Next = NonNullable<TodayShowOutput['next_mesocycle']>;

// MESO-060, PLAN-027, MESO-064: "Build your next mesocycle" on Today, with an active rest
// offered when the server recommends one. The server writes every line. Ported from
// rp-clone's NextMesocycleCard.
withDefaults(defineProps<{ next: Next; busy?: boolean }>(), { busy: false });

const emit = defineEmits<{ choose: [route: Next['options'][number]['route']]; takeActiveRest: [] }>();
</script>

<template>
    <section class="flex flex-col gap-3 rounded-2xl bg-surface p-3.5" data-test="next-mesocycle">
        <div>
            <h2 class="text-base font-semibold text-primary-text">{{ next.title }}</h2>
            <p class="text-sm text-secondary-text">{{ next.start_text }}</p>
        </div>
        <div v-if="next.active_rest" class="flex flex-col items-start gap-2 rounded-xl p-2.5" :class="toneClasses.info">
            <p class="text-sm font-semibold">{{ next.active_rest.text }}</p>
            <p class="text-sm">{{ next.active_rest.start_text }}</p>
            <button
                type="button"
                class="min-h-tap rounded-lg bg-surface px-3 text-accent disabled:opacity-50"
                :disabled="busy"
                data-test="active-rest"
                @click="emit('takeActiveRest')"
            >
                Take an active rest
            </button>
        </div>
        <div class="flex flex-col gap-2">
            <button
                v-for="(option, index) in next.options"
                :key="option.route"
                type="button"
                class="min-h-tap rounded-xl px-3 font-medium disabled:opacity-50"
                :class="index === 0 ? 'bg-accent text-on-accent' : 'bg-accent/15 text-accent'"
                :disabled="busy"
                data-test="option"
                @click="emit('choose', option.route)"
            >
                {{ option.label }}
            </button>
        </div>
    </section>
</template>
