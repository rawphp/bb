<script setup lang="ts">
import { ref } from 'vue';
import { useRouter } from 'vue-router';
import type { TrainShowOutput } from '@/contracts/train_show';
import ReadState from './ReadState.vue';
import { useScreenRead } from './useScreenRead';

// Train → Mesocycles: the unfinished draft (MESO-007), every mesocycle grouped current,
// planned and past (MESO-001), and the + menu with the four ways to build one (MESO-002).
// Ported from rp-clone's MesocyclesView.
const props = defineProps<{ payload?: TrainShowOutput }>();

type CreateRoute = TrainShowOutput['create_routes'][number];

// The build flow (MesocycleBuildView, #109) opens here, on a route, or on the draft.
const BUILD_PATH = '/train/mesocycles/build';

const router = useRouter();
const { data, failure, load } = useScreenRead<TrainShowOutput>('train_show', () => ({}), props.payload);

const menuOpen = ref(false);
const replacing = ref<CreateRoute | null>(null);

function build(route: CreateRoute): void {
    void router.push({ path: BUILD_PATH, query: { route: route.route, ...(route.copy_source_id ? { copy: route.copy_source_id } : {}) } });
}

// Starting another build replaces the draft, so it asks first (MESO-007).
function choose(route: CreateRoute): void {
    menuOpen.value = false;
    if (data.value?.draft) replacing.value = route;
    else build(route);
}

function confirmReplace(): void {
    const route = replacing.value;
    replacing.value = null;
    if (route) build(route);
}
</script>

<template>
    <main class="bb-screen-content">
        <header class="mb-4 flex items-center gap-2">
            <span class="min-w-tap" aria-hidden="true" />
            <h1 class="flex-1 text-center text-base font-semibold">Mesocycles</h1>
            <div class="relative">
                <button
                    type="button"
                    class="flex min-h-tap min-w-tap items-center justify-center rounded-full text-2xl text-primary-text disabled:opacity-50"
                    aria-label="New mesocycle"
                    :aria-expanded="menuOpen"
                    :disabled="!data"
                    data-test="new"
                    @click="menuOpen = !menuOpen"
                >
                    +
                </button>
                <div v-if="menuOpen && data" role="menu" class="absolute right-0 z-10 mt-1 w-64 overflow-hidden rounded-xl bg-surface shadow-lg">
                    <button
                        v-for="route in data.create_routes"
                        :key="route.route"
                        type="button"
                        role="menuitem"
                        class="flex min-h-tap w-full flex-col justify-center px-4 py-2 text-left text-primary-text disabled:text-secondary-text"
                        :disabled="!route.available"
                        :data-test="`route-${route.route}`"
                        @click="choose(route)"
                    >
                        <span>{{ route.title }}</span>
                        <span v-if="route.priority_notice" class="text-sm text-secondary-text">{{ route.priority_notice }}</span>
                    </button>
                </div>
            </div>
        </header>

        <template v-if="data">
            <section v-if="data.draft" class="mb-6">
                <h2 class="px-4 pb-1.5 text-sm font-semibold text-secondary-text">Unfinished</h2>
                <RouterLink
                    :to="{ path: BUILD_PATH, query: { draft: data.draft.id } }"
                    class="flex min-h-tap items-center gap-2 rounded-2xl bg-surface px-4 py-3"
                    aria-label="Continue draft mesocycle"
                    data-test="draft"
                >
                    <span class="flex-1">
                        <span class="block text-primary-text">{{ data.draft.name }}</span>
                        <span class="block text-sm text-secondary-text">{{ data.draft.text }}</span>
                    </span>
                    <svg viewBox="0 0 24 24" class="size-6 text-accent" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                        <circle cx="12" cy="12" r="9.5" />
                        <path d="m8.5 15.5 1-3 5-5 2 2-5 5-3 1Z" stroke-linejoin="round" />
                    </svg>
                </RouterLink>
            </section>

            <section v-for="section in data.sections" :key="section.status" class="mb-6">
                <h2 class="px-4 pb-1.5 text-sm font-semibold text-secondary-text">{{ section.title }}</h2>
                <div class="divide-y divide-secondary-text/15 overflow-hidden rounded-2xl bg-surface">
                    <RouterLink
                        v-for="row in section.rows"
                        :key="row.id"
                        :to="`/train/mesocycles/${row.id}`"
                        class="flex min-h-tap items-center gap-2 px-4 py-3"
                        data-test="row"
                    >
                        <span class="flex-1">
                            <span class="block text-primary-text">{{ row.name }}</span>
                            <span class="block text-sm text-secondary-text">{{ row.dates_text }}</span>
                            <span class="block text-sm text-secondary-text">{{ row.summary_text }}</span>
                        </span>
                        <svg viewBox="0 0 24 24" class="size-4 text-secondary-text" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path d="m9 5 7 7-7 7" />
                        </svg>
                    </RouterLink>
                </div>
            </section>

            <p v-if="!data.draft && !data.sections.length" class="px-4 text-secondary-text">No mesocycles yet. Tap + to build one.</p>
        </template>
        <ReadState v-else :failure="failure" what="Your mesocycles" @retry="load" />

        <div v-if="replacing && data?.draft" class="fixed inset-0 z-20 flex items-center justify-center bg-black/30 p-8">
            <div role="alertdialog" aria-modal="true" aria-labelledby="replace-title" class="w-full space-y-3 rounded-2xl bg-surface p-4 text-center">
                <h2 id="replace-title" class="font-semibold">Replace your draft?</h2>
                <p class="text-sm">{{ data.draft.replace_warning }}</p>
                <button type="button" class="min-h-tap w-full text-tone-warning" data-test="replace" @click="confirmReplace">Replace draft</button>
                <button type="button" class="min-h-tap w-full font-semibold text-accent" data-test="cancel" @click="replacing = null">Cancel</button>
            </div>
        </div>
    </main>
</template>
