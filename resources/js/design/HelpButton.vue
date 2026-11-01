<script setup lang="ts">
import { nextTick, ref, useId } from 'vue';
import { RouterLink, type RouteLocationRaw } from 'vue-router';

// A "?" icon that opens a short sheet with a Read more link (UI-009). On a
// feature screen, `to` is the screen's Learn article (LRN-003). Ported from
// rp-clone's HelpButton.swift.
const props = defineProps<{
    title: string;
    message: string;
    // Read more goes to a URL (`href`), an in-app route (`to`), or, with neither, emits read-more.
    href?: string;
    to?: RouteLocationRaw;
    state?: 'loading' | 'error' | 'pending';
    disabled?: boolean;
}>();

const emit = defineEmits<{ 'read-more': [] }>();

const isShowing = ref(false);
const trigger = ref<HTMLButtonElement>();
const sheet = ref<HTMLElement>();
const titleId = useId();

async function show(): Promise<void> {
    isShowing.value = true;
    await nextTick();
    sheet.value?.focus();
}

async function hide(): Promise<void> {
    isShowing.value = false;
    await nextTick();
    trigger.value?.focus();
}

function readMore(): void {
    void hide();
    emit('read-more');
}

const readMoreClass = 'inline-flex min-h-tap items-center text-accent';
</script>

<template>
    <button
        ref="trigger"
        type="button"
        aria-label="Help"
        :aria-description="props.title"
        aria-haspopup="dialog"
        :aria-expanded="isShowing"
        :disabled="props.disabled"
        class="inline-flex min-h-tap min-w-tap items-center justify-center text-accent disabled:text-secondary-text"
        @click="show"
    >
        <svg viewBox="0 0 24 24" class="size-6" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
            <circle cx="12" cy="12" r="9.25" />
            <path d="M9.5 9.25a2.5 2.5 0 1 1 3.5 2.3c-.6.27-1 .87-1 1.53v.67" stroke-linecap="round" />
            <circle cx="12" cy="16.75" r=".6" fill="currentColor" stroke="none" />
        </svg>
    </button>

    <div v-if="isShowing" class="fixed inset-0 z-50 flex flex-col justify-end">
        <button type="button" aria-label="Close help" class="absolute inset-0 bg-background/80" @click="hide" />
        <div
            ref="sheet"
            role="dialog"
            aria-modal="true"
            :aria-labelledby="titleId"
            tabindex="-1"
            class="relative flex max-h-[90dvh] min-h-[50dvh] flex-col gap-3 overflow-y-auto rounded-t-2xl bg-surface p-5 text-primary-text outline-none"
            @keydown.esc="hide"
        >
            <h2 :id="titleId" class="text-xl font-bold">{{ props.title }}</h2>

            <div v-if="props.state === 'loading'" aria-busy="true" class="flex flex-col gap-2">
                <span class="sr-only">Loading</span>
                <span class="h-4 w-full animate-pulse rounded bg-background" />
                <span class="h-4 w-2/3 animate-pulse rounded bg-background" />
            </div>
            <p v-else-if="props.state === 'error'" role="alert" class="text-secondary-text">Couldn't load this help.</p>
            <template v-else-if="props.message">
                <p :class="{ 'opacity-50': props.state === 'pending' }">{{ props.message }}</p>
                <span v-if="props.state === 'pending'" class="text-sm text-secondary-text">Waiting to sync</span>
            </template>

            <a v-if="props.href" :href="props.href" :class="readMoreClass">Read more</a>
            <RouterLink v-else-if="props.to" :to="props.to" :class="readMoreClass" @click="hide">Read more</RouterLink>
            <button v-else type="button" :class="readMoreClass" @click="readMore">Read more</button>
        </div>
    </div>
</template>
