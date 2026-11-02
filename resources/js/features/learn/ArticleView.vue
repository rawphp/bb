<script setup lang="ts">
import { computed } from 'vue';
import { useScreenRead } from '@/app/useScreenRead';
import type { ArticleShowOutput } from '@/contracts/article_show';

// One Learn article, in the blocks article_show returns (LRN-001). Ported from
// rp-clone's ArticleView. /dev/screens/article_show hands it a fixture as payload.
type Block = ArticleShowOutput['blocks'][number];
type Span = Block['spans'][number];

const props = defineProps<{ slug?: string; payload?: ArticleShowOutput }>();

const { data: article, failure } = useScreenRead<ArticleShowOutput>('article_show', props.payload, () => ({ slug: props.slug ?? '' }));

// Consecutive bullet blocks render as one list; every other block stands alone.
const groups = computed(() => {
    const out: { bullets: boolean; blocks: Block[] }[] = [];
    for (const block of article.value?.blocks ?? []) {
        const last = out.at(-1);
        if (block.kind === 'bullet' && last?.bullets) last.blocks.push(block);
        else out.push({ bullets: block.kind === 'bullet', blocks: [block] });
    }
    return out;
});

function spanTag(span: Span): 'strong' | 'em' | 'span' {
    return span.bold ? 'strong' : span.italic ? 'em' : 'span';
}
</script>

<template>
    <main class="min-h-dvh bg-surface p-5 text-primary-text">
        <article v-if="article" class="flex flex-col gap-3">
            <h1 class="text-center text-base font-semibold">{{ article.title }}</h1>
            <template v-for="(group, index) in groups" :key="index">
                <ul v-if="group.bullets" class="flex flex-col gap-3">
                    <li v-for="(block, b) in group.blocks" :key="b" class="flex items-baseline gap-2">
                        <span aria-hidden="true">•</span>
                        <span><component :is="spanTag(span)" v-for="(span, s) in block.spans" :key="s" :class="{ italic: span.bold && span.italic }">{{ span.text }}</component></span>
                    </li>
                </ul>
                <component :is="group.blocks[0]!.kind === 'heading' ? 'h2' : 'p'" v-else :class="{ 'pt-2 font-semibold': group.blocks[0]!.kind === 'heading' }">
                    <component :is="spanTag(span)" v-for="(span, s) in group.blocks[0]!.spans" :key="s" :class="{ italic: span.bold && span.italic }">{{ span.text }}</component>
                </component>
            </template>
        </article>

        <p v-else-if="failure" role="alert" class="text-tone-warning">
            {{ failure === 'signed-out' ? 'You are signed out.' : 'This article could not be loaded.' }}
        </p>

        <div v-else aria-busy="true" class="flex flex-col gap-3">
            <span class="sr-only">Loading</span>
            <span aria-hidden="true" class="mx-auto block h-4 w-40 animate-pulse rounded bg-background" />
            <span v-for="(w, n) in ['w-full', 'w-full', 'w-2/3']" :key="n" aria-hidden="true" class="block h-4 animate-pulse rounded bg-background" :class="w" />
        </div>
    </main>
</template>
