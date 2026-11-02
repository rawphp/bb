<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { CapabilityError, read } from '@/app/call';
import type { ArticleShowOutput } from '@/contracts/article_show';

// One Learn article, in the blocks article_show returns (LRN-001). Ported from
// rp-clone's ArticleView. /dev/screens/article_show hands it a fixture as payload.
type Span = ArticleShowOutput['blocks'][number]['spans'][number];

const props = defineProps<{ slug?: string; payload?: ArticleShowOutput }>();

const article = ref<ArticleShowOutput | undefined>(props.payload);
const failure = ref<'signed-out' | 'error' | null>(null);

function fail(error: unknown): void {
    article.value = undefined;
    failure.value = error instanceof CapabilityError && error.status === 401 ? 'signed-out' : 'error';
}

function spanTag(span: Span): 'strong' | 'em' | 'span' {
    return span.bold ? 'strong' : span.italic ? 'em' : 'span';
}

onMounted(async () => {
    if (article.value) return;
    try {
        // A cached copy shows at once; the refresh replaces it, or takes it away when it is refused (OFF-003).
        const data = await read<ArticleShowOutput>('article_show', { slug: props.slug ?? '' }, (fresh) => (article.value = fresh), fail);
        if (failure.value === null) article.value ??= data;
    } catch (error) {
        fail(error);
    }
});
</script>

<template>
    <main class="min-h-dvh bg-surface p-5 text-primary-text">
        <article v-if="article" class="flex flex-col gap-3">
            <h1 class="text-center text-base font-semibold">{{ article.title }}</h1>
            <template v-for="(block, index) in article.blocks" :key="index">
                <ul v-if="block.kind === 'bullet'">
                    <li class="flex items-baseline gap-2">
                        <span aria-hidden="true">•</span>
                        <span><component :is="spanTag(span)" v-for="(span, s) in block.spans" :key="s" :class="{ italic: span.bold && span.italic }">{{ span.text }}</component></span>
                    </li>
                </ul>
                <component :is="block.kind === 'heading' ? 'h2' : 'p'" v-else :class="{ 'pt-2 font-semibold': block.kind === 'heading' }">
                    <component :is="spanTag(span)" v-for="(span, s) in block.spans" :key="s" :class="{ italic: span.bold && span.italic }">{{ span.text }}</component>
                </component>
            </template>
        </article>

        <p v-else-if="failure" role="alert" class="text-secondary-text">
            {{ failure === 'signed-out' ? 'You are signed out.' : 'This article could not be loaded.' }}
        </p>

        <div v-else aria-busy="true" class="flex flex-col gap-3">
            <span class="sr-only">Loading</span>
            <span aria-hidden="true" class="mx-auto block h-4 w-40 animate-pulse rounded bg-background" />
            <span v-for="(w, n) in ['w-full', 'w-full', 'w-2/3']" :key="n" aria-hidden="true" class="block h-4 animate-pulse rounded bg-background" :class="w" />
        </div>
    </main>
</template>
