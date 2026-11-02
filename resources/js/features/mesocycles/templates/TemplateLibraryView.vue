<script setup lang="ts">
import { onMounted, ref, watch } from 'vue';
import { RouterLink } from 'vue-router';
import ReadState from '@/app/ReadState.vue';
import { screenRead, type ReadFailure } from '@/app/screenRead';
import type { TemplatesShowInput, TemplatesShowOutput } from '@/contracts/templates_show';

type Filter = TemplatesShowOutput['filters'][number];

// The template library (MESO-010, MESO-011, MESO-015), ported from rp-clone's
// TemplateLibraryView: filter chips and a row per template. Which templates match, and
// the words on every chip, come from templates_show; the script holds only the filters
// the user has picked. Left alone, a filter is the server's default (the phase in force).
// `picking` is the build flow's template step: a row opens the template there instead of
// linking to its page. Templates are library data no write changes, so nothing here waits to sync.
const props = withDefaults(defineProps<{ payload?: TemplatesShowOutput; picking?: boolean }>(), { payload: undefined, picking: false });

const emit = defineEmits<{ open: [id: string] }>();

const TITLES: Record<Filter['key'], string> = { days: 'Days', sex: 'Sex', focus: 'Focus', phase: 'Phase' };

const picked = ref<TemplatesShowInput>({});
const data = ref<TemplatesShowOutput | undefined>(props.payload);
const failure = ref<ReadFailure | null>(null);

const read = screenRead<TemplatesShowOutput>(
    'templates_show',
    'The templates could not be loaded.',
    (fresh) => {
        failure.value = null;
        data.value = fresh;
    },
    (why) => {
        data.value = undefined;
        failure.value = why;
    },
);

function load(): Promise<void> {
    return read({ ...picked.value });
}

watch(picked, () => void load());
onMounted(() => {
    if (!data.value) void load();
});

// The select goes on showing what the server last said until the new list comes back.
function choose(filter: Filter, event: Event): void {
    const select = event.target as HTMLSelectElement;
    const option = filter.options[select.selectedIndex];
    select.selectedIndex = filter.options.findIndex((o) => o.selected);
    if (option) picked.value = { ...picked.value, [filter.key]: option.value ?? null } as TemplatesShowInput;
}
</script>

<template>
    <component :is="picking ? 'div' : 'main'" :class="{ 'bb-screen-content': !picking }">
        <h1 v-if="!picking" class="mb-4 text-center text-base font-semibold">Templates</h1>

        <template v-if="data">
            <div class="-mx-4 flex gap-2 overflow-x-auto px-4 pb-3">
                <span
                    v-for="filter in data.filters"
                    :key="filter.key"
                    data-test="filter"
                    class="relative flex min-h-tap shrink-0 items-center gap-1 rounded-full px-3 text-sm font-medium"
                    :class="filter.active ? 'bg-accent text-on-accent' : 'bg-surface text-primary-text'"
                >
                    <select
                        class="absolute inset-0 size-full cursor-pointer opacity-0"
                        :aria-label="`${TITLES[filter.key]} filter`"
                        @change="choose(filter, $event)"
                    >
                        <option v-for="option in filter.options" :key="option.label" :selected="option.selected">{{ option.label }}</option>
                    </select>
                    <span aria-hidden="true" data-test="filter-label">{{ filter.label }}</span>
                    <svg viewBox="0 0 12 12" class="size-3" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M2.5 4.5l3.5 3.5 3.5-3.5" />
                    </svg>
                </span>
            </div>

            <p v-if="data.rows.length === 0 && data.empty_text" class="px-4 text-sm text-secondary-text">{{ data.empty_text }}</p>
            <ul v-else class="divide-y divide-secondary-text/15 rounded-2xl bg-surface px-4">
                <li v-for="row in data.rows" :key="row.id">
                    <!-- One row: a button that opens the detail in the build flow, else a link to it. -->
                    <component
                        :is="picking ? 'button' : RouterLink"
                        v-bind="picking ? { type: 'button', onClick: () => emit('open', row.id) } : { to: `/train/templates/${row.id}` }"
                        data-test="template"
                        class="flex min-h-tap w-full items-center gap-3 py-1.5 text-left active:opacity-70"
                    >
                        <span class="flex min-w-0 grow flex-col">
                            <span class="text-primary-text">{{ row.name }}</span>
                            <span class="text-sm text-secondary-text">{{ row.summary }}</span>
                        </span>
                        <svg viewBox="0 0 8 14" class="h-3.5 shrink-0 text-secondary-text" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M1.5 1.5L6.5 7l-5 5.5" /></svg>
                    </component>
                </li>
            </ul>
        </template>
        <ReadState v-else :failure="failure" what="The templates" @retry="load" />
    </component>
</template>
