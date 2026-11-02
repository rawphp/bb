<script setup lang="ts">
import { RouterLink } from 'vue-router';
import { useRead } from '@/app/useRead';
import type { LearnShowOutput } from '@/contracts/learn_show';
import ReadState from '@/app/ReadState.vue';

// The Learn index, grouped by section (LRN-001, LRN-002, LRN-005). Ported from
// rp-clone's LearnIndexView. /dev/screens/learn_show hands it a fixture as payload.
const props = defineProps<{ payload?: LearnShowOutput }>();

const { data: learn, failure, load } = useRead<LearnShowOutput>('learn_show', {}, 'Learn could not be loaded.', props.payload);
</script>

<template>
    <main class="min-h-dvh bg-background px-4 pb-8 pt-6 text-primary-text">
        <h1 class="px-2 text-3xl font-bold">Learn</h1>

        <template v-if="learn">
            <p v-if="learn.sections.length === 0" class="mt-6 px-2 text-secondary-text">No articles yet.</p>
            <section v-for="section in learn.sections" :key="section.title" class="mt-6">
                <h2 class="px-4 pb-1.5 text-sm uppercase text-secondary-text">{{ section.title }}</h2>
                <ul class="overflow-hidden rounded-xl bg-surface">
                    <li v-for="article in section.articles" :key="article.slug" class="border-secondary-text/25 [&+&]:border-t">
                        <RouterLink :to="`/learn/${article.slug}`" class="flex min-h-tap items-center justify-between gap-3 px-4 py-2.5">
                            <span>{{ article.title }}</span>
                            <svg viewBox="0 0 24 24" class="size-4 shrink-0 text-secondary-text" fill="none" stroke="currentColor" stroke-width="2.25" aria-hidden="true">
                                <path d="M9 5l7 7-7 7" stroke-linecap="round" stroke-linejoin="round" />
                            </svg>
                        </RouterLink>
                    </li>
                </ul>
            </section>
        </template>

        <div v-else class="mt-6">
            <ReadState :failure="failure" what="Learn" @retry="load" />
        </div>
    </main>
</template>
