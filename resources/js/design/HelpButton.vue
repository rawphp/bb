<script setup lang="ts">
import { nextTick, ref, useId } from 'vue';
import { RouterLink, type RouteLocationRaw } from 'vue-router';
import { useModalFocus } from './useModalFocus';

// A "?" icon that opens a short sheet with a Read more link (UI-009). On a
// feature screen, `to` is the screen's Learn article (LRN-003). Ported from
// rp-clone's HelpButton.swift. With `opens`, the icon only emits open, for a screen
// that shows its own help sheet.
defineOptions({ inheritAttrs: false });

const props = withDefaults(
    defineProps<{
        title: string;
        message: string;
        // Read more goes to a URL (`href`), an in-app route (`to`), or, with neither, emits read-more.
        href?: string;
        to?: RouteLocationRaw;
        /** The message is still loading: a busy placeholder shows in its place. */
        loading?: boolean;
        /** Why the message could not load; shown in its place. */
        error?: string | null;
        /** The message is server-derived and waiting on the outbox (OFF-008). */
        pending?: boolean;
        disabled?: boolean;
        /** The icon's accessible name. */
        label?: string;
        /** A tap emits open and shows no sheet here: the screen opens its own. */
        opens?: boolean;
    }>(),
    { loading: false, error: null, pending: false, disabled: false, label: 'Help', opens: false },
);

const emit = defineEmits<{ 'read-more': []; open: [] }>();

const isShowing = ref(false);
const trigger = ref<HTMLButtonElement>();
const sheet = ref<HTMLElement>();
const titleId = useId();
// The sheet is modal: Tab and Shift+Tab cycle through its own controls and never reach the page behind it.
const focus = useModalFocus(sheet);

async function show(): Promise<void> {
    if (props.opens) {
        emit('open');
        return;
    }
    isShowing.value = true;
    await focus.opened(trigger.value);
}

async function hide(): Promise<void> {
    isShowing.value = false;
    await nextTick();
    focus.closed();
}

function readMore(): void {
    void hide();
    emit('read-more');
}

const readMoreClass = 'inline-flex min-h-tap items-center text-accent';
</script>

<template>
    <button
        v-bind="$attrs"
        ref="trigger"
        type="button"
        :aria-label="props.label"
        :aria-description="props.title"
        aria-haspopup="dialog"
        :aria-expanded="props.opens ? undefined : isShowing"
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

    <!-- On <body>, so a parent's overflow, transform or z-index cannot clip or bury the sheet. -->
    <Teleport to="body">
        <div
            v-if="isShowing"
            data-help-overlay
            class="fixed inset-0 z-50 flex flex-col justify-end"
            @keydown.esc="hide"
            @keydown.tab="focus.keepFocus"
        >
            <div data-scrim aria-hidden="true" class="absolute inset-0 bg-scrim" @click="hide" />
            <div
                ref="sheet"
                role="dialog"
                aria-modal="true"
                :aria-labelledby="titleId"
                tabindex="-1"
                class="relative flex max-h-[90dvh] min-h-[50dvh] flex-col gap-3 overflow-y-auto rounded-t-2xl bg-surface p-5 text-primary-text outline-none"
            >
                <div class="flex items-start justify-between gap-2">
                    <h2 :id="titleId" class="pt-2 text-xl font-bold">{{ props.title }}</h2>
                    <button
                        type="button"
                        aria-label="Close help"
                        class="-mr-3 -mt-3 inline-flex min-h-tap min-w-tap items-center justify-center text-secondary-text"
                        @click="hide"
                    >
                        <svg viewBox="0 0 24 24" class="size-5" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path d="M6 6l12 12M18 6L6 18" stroke-linecap="round" />
                        </svg>
                    </button>
                </div>

                <div v-if="props.loading" aria-busy="true" class="flex flex-col gap-2">
                    <span class="sr-only">Loading</span>
                    <span class="h-4 w-full animate-pulse rounded bg-background" />
                    <span class="h-4 w-2/3 animate-pulse rounded bg-background" />
                </div>
                <p v-else-if="props.error" role="alert" class="text-tone-warning">{{ props.error }}</p>
                <p v-else-if="props.message" :class="{ 'opacity-50': props.pending }">{{ props.message }}</p>
                <span v-if="props.pending && !props.loading && !props.error" class="text-sm text-secondary-text">Waiting to sync</span>

                <a v-if="props.href" :href="props.href" :class="readMoreClass">Read more</a>
                <RouterLink v-else-if="props.to" :to="props.to" :class="readMoreClass" @click="hide">Read more</RouterLink>
                <button v-else type="button" :class="readMoreClass" @click="readMore">Read more</button>
            </div>
        </div>
    </Teleport>
</template>
