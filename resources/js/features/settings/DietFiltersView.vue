<script setup lang="ts">
import ReadState from '@/app/ReadState.vue';
import { useRead } from '@/app/useRead';
import { useWrite } from '@/app/useWrite';
import type { DietFiltersSetInput } from '@/contracts/diet_filters_set';
import type { SettingsShowOutput } from '@/contracts/settings_show';
import { toneClasses } from '@/design/tones';

// Settings → Diet filters (SET-006, FOOD-027): tick as many as apply. Each tap sends the
// whole set (diet_filters_set), and the ticks show what the server then holds.
const props = defineProps<{ payload?: SettingsShowOutput }>();

const { data: settings, failure, load } = useRead<SettingsShowOutput>('settings_show', {}, 'Your diet filters could not be loaded.', props.payload);
const { send, busy, error } = useWrite(load);

type Filter = DietFiltersSetInput['filters'][number]['filter'];

async function toggle(value: string): Promise<void> {
    if (!settings.value) return;
    const filters = settings.value.diet_filters.choices
        .filter((choice) => (choice.value === value ? !choice.selected : choice.selected))
        .map((choice) => ({ filter: choice.value as Filter }));
    await send('diet_filters_set', { filters } satisfies DietFiltersSetInput, 'Your diet filters could not be saved. Try again.');
}
</script>

<template>
    <main class="bb-screen-content space-y-4">
        <h1 class="text-center text-lg font-semibold">Diet filters</h1>

        <template v-if="settings">
            <p v-if="error" class="rounded-2xl p-4 text-sm" :class="toneClasses.warning" role="alert">{{ error }}</p>
            <section>
                <div class="divide-y divide-secondary-text/20 overflow-hidden rounded-2xl bg-surface">
                    <button
                        v-for="choice in settings.diet_filters.choices"
                        :key="choice.value"
                        type="button"
                        :data-test="`filter-${choice.value}`"
                        :aria-pressed="choice.selected"
                        :disabled="busy"
                        class="flex min-h-tap w-full items-center justify-between gap-3 px-4 py-2 text-left active:opacity-70 disabled:opacity-60"
                        @click="toggle(choice.value)"
                    >
                        <span>{{ choice.label }}</span>
                        <svg v-if="choice.selected" aria-hidden="true" viewBox="0 0 24 24" class="size-5 text-accent" fill="none" stroke="currentColor" stroke-width="2.5">
                            <path d="M5 12.5l4.5 4.5L19 7.5" />
                        </svg>
                    </button>
                </div>
                <p class="px-4 pt-2 text-sm text-secondary-text">Foods that do not match every filter you pick are hidden.</p>
            </section>
        </template>

        <ReadState v-else :failure="failure" what="Your diet filters" @retry="load" />
    </main>
</template>
