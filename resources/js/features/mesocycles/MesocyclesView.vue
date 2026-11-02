<script setup lang="ts">
import { computed, ref } from 'vue';
import { useRouter } from 'vue-router';
import { failureMessage } from '@/app/errors';
import ReadState from '@/app/ReadState.vue';
import { useScreenRead } from '@/app/useScreenRead';
import type { TrainShowOutput } from '@/contracts/train_show';
import BottomSheet from '@/design/BottomSheet.vue';
import ActionMenu, { type ActionMenuItem } from '@/design/ActionMenu.vue';

// Train → Mesocycles: the unfinished draft (MESO-007), every mesocycle grouped current,
// planned and past (MESO-001), and the + menu with the four ways to build one (MESO-002).
// Ported from rp-clone's MesocyclesView.
const props = defineProps<{ payload?: TrainShowOutput }>();

type CreateRoute = TrainShowOutput['create_routes'][number];

// The build flow (MesocycleBuildView, #109) opens here, on a route, or on the draft.
const BUILD_PATH = '/train/mesocycles/build';

const router = useRouter();
const { data, failure, load } = useScreenRead<TrainShowOutput>('train_show', props.payload);

const replacing = ref<CreateRoute | null>(null);
const checking = ref(false);
const checkError = ref<string | null>(null);

const menuItems = computed<ActionMenuItem[]>(() =>
    (data.value?.create_routes ?? []).map((route) => ({
        key: route.route,
        title: route.title,
        detail: route.priority_notice,
        disabled: !route.available,
    })),
);

function build(route: CreateRoute): void {
    void router.push({ path: BUILD_PATH, query: { route: route.route, ...(route.copy_source_id ? { copy: route.copy_source_id } : {}) } });
}

// Starting another build replaces the draft, so it asks first (MESO-007). Whether there
// is a draft comes from the server, never the cached copy, so a draft started since
// (on another screen or device) still gets the question. With no answer, nothing starts.
// The check is the screen's own read, so a background refresh that started before it
// cannot replace its answer.
async function choose(key: string): Promise<void> {
    checkError.value = null;
    if (props.payload === undefined) {
        checking.value = true;
        try {
            await load({ fresh: true });
        } catch (error) {
            checkError.value = failureMessage(error, 'Your mesocycles could not be checked for a draft.');
            return;
        } finally {
            checking.value = false;
        }
    }
    const route = data.value?.create_routes.find((r) => r.route === key);
    if (!route?.available) return;
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
            <ActionMenu label="New mesocycle" :items="menuItems" :disabled="!data || checking" @select="choose">
                <svg viewBox="0 0 24 24" class="size-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                    <path d="M12 5v14M5 12h14" />
                </svg>
            </ActionMenu>
        </header>

        <p v-if="checkError" role="alert" class="mb-4 rounded-2xl bg-tone-warning-bg p-3 text-sm text-tone-warning" data-test="error">{{ checkError }}</p>

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

        <BottomSheet
            :open="!!replacing && !!data?.draft"
            title="Replace your draft?"
            save-title="Replace draft"
            :heights="['half']"
            @cancel="replacing = null"
            @save="confirmReplace"
        >
            <p v-if="data?.draft" class="p-4 text-center text-sm">{{ data.draft.replace_warning }}</p>
        </BottomSheet>
    </main>
</template>
