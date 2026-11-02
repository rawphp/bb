<script lang="ts">
/** A resting height: half the screen, or the whole screen under the status bar. */
export type SheetHeight = 'half' | 'full';

// Open sheets, oldest first. Only the top one answers Escape and Tab, so a sheet opened over another closes first.
const openSheets: symbol[] = [];
</script>

<script setup lang="ts">
import { computed, onBeforeUnmount, ref, useId, watch } from 'vue';
import { useModalFocus } from './useModalFocus';

// Bottom sheet frame: Cancel left, title middle, Save right (UI-005), ported from rp-clone's SheetScaffold.swift.
// It springs open over a dimmed screen and drags down to close (PWA-023), unless `dismissible` is off (UI-022).
const props = withDefaults(
    defineProps<{
        open: boolean;
        title: string;
        /** null for a sheet that answers by its own choices: no Save. */
        saveTitle?: string | null;
        saveDisabled?: boolean;
        cancelDisabled?: boolean;
        /** Resting heights, lowest first; the sheet opens at the first. */
        heights?: SheetHeight[];
        /** Off: a tap outside, Escape and a drag down do not close it; only Cancel does (UI-022). */
        dismissible?: boolean;
        loading?: boolean;
        error?: string | null;
        /** What the sheet saved is waiting in the outbox (OFF-009). */
        pending?: boolean;
    }>(),
    {
        saveTitle: 'Save',
        saveDisabled: false,
        cancelDisabled: false,
        heights: () => ['full'],
        dismissible: true,
        loading: false,
        error: null,
        pending: false,
    },
);

const emit = defineEmits<{ cancel: []; save: [] }>();

const titleId = useId();
const panel = ref<HTMLElement | null>(null);
const level = ref(0);
const height = computed<SheetHeight>(() => props.heights[level.value] ?? 'full');
const adjustable = computed(() => props.heights.length > 1);

// How far a drag must travel to change height or close.
const THRESHOLD = 80;
// How far a finger may drift before a tap becomes a drag, so a tap still clicks Cancel, Save or the grabber.
const SLOP = 8;
let startY: number | null = null;
const offset = ref(0);
const dragging = ref(false);

const focus = useModalFocus(panel);
const token = Symbol('sheet');

watch(
    () => props.open,
    async (open) => {
        if (open) {
            level.value = 0;
            openSheets.push(token);
            document.addEventListener('keydown', onKeydown);
            await focus.opened();
        } else {
            close();
        }
    },
    { immediate: true },
);

onBeforeUnmount(close);

function close(): void {
    const index = openSheets.indexOf(token);
    if (index !== -1) {
        openSheets.splice(index, 1);
    }
    document.removeEventListener('keydown', onKeydown);
    focus.closed();
}

// Listened for on the document, so Escape and Tab still work after focus falls to <body>
// (a tap on the dim, or a focused control that the parent disables or removes).
// A key pressed in another modal over the sheet, such as a HelpButton's, belongs to that modal.
function onKeydown(event: KeyboardEvent): void {
    if (openSheets[openSheets.length - 1] !== token || event.defaultPrevented) {
        return;
    }
    const modal = event.target instanceof Element ? event.target.closest('[aria-modal="true"]') : null;
    if (modal && modal !== panel.value) {
        return;
    }
    if (event.key === 'Escape') {
        dismiss();
    } else if (event.key === 'Tab') {
        focus.keepFocus(event);
    }
}

function dismiss(): void {
    if (props.dismissible) {
        emit('cancel');
    }
}

function onPointerDown(event: PointerEvent): void {
    startY = event.clientY;
}

function onPointerMove(event: PointerEvent): void {
    if (startY === null) {
        return;
    }
    const dy = event.clientY - startY;
    // Capture only once the finger has moved past the slop: capturing earlier sends a tap's click to the handle.
    if (!dragging.value) {
        if (Math.abs(dy) < SLOP) {
            return;
        }
        (event.currentTarget as HTMLElement).setPointerCapture?.(event.pointerId);
        dragging.value = true;
    }
    const canGoDown = level.value > 0 || props.dismissible;
    const canGoUp = level.value < props.heights.length - 1;
    // Past the last stop the sheet resists, as on iPhone.
    offset.value = (dy > 0 && !canGoDown) || (dy < 0 && !canGoUp) ? dy / 4 : dy;
}

function onPointerUp(event: PointerEvent): void {
    if (startY === null) {
        return;
    }
    const dy = event.clientY - startY;
    resetDrag();

    if (dy > THRESHOLD) {
        if (level.value > 0) {
            level.value--;
        } else {
            dismiss();
        }
    } else if (dy < -THRESHOLD && level.value < props.heights.length - 1) {
        level.value++;
    }
}

// Ends a drag. A pointercancel (the browser took the gesture) runs only this: the sheet springs back and nothing changes.
function resetDrag(): void {
    startY = null;
    offset.value = 0;
    dragging.value = false;
}

function toggleHeight(): void {
    level.value = level.value === 0 ? props.heights.length - 1 : 0;
}
</script>

<template>
    <!-- On <body>, so a parent's overflow, transform or z-index cannot clip or bury the sheet. -->
    <Teleport to="body">
        <Transition
            enter-active-class="transition-opacity duration-300 motion-reduce:transition-none"
            leave-active-class="transition-opacity duration-200 motion-reduce:transition-none"
            enter-from-class="opacity-0"
            leave-to-class="opacity-0"
        >
            <div v-if="open" data-scrim aria-hidden="true" class="fixed inset-0 z-40 bg-scrim" @click="dismiss" />
        </Transition>
        <!-- The wrapper springs in and out; the panel inside follows the finger and changes height. -->
        <Transition
            enter-active-class="transition-transform duration-500 ease-[cubic-bezier(0.2,1.15,0.4,1)] motion-reduce:transition-none"
            leave-active-class="transition-transform duration-200 ease-in motion-reduce:transition-none"
            enter-from-class="translate-y-full"
            leave-to-class="translate-y-full"
        >
            <div v-if="open" class="fixed inset-x-0 bottom-0 z-50">
                <div
                    ref="panel"
                    role="dialog"
                    aria-modal="true"
                    :aria-labelledby="titleId"
                    :data-height="height"
                    tabindex="-1"
                    class="flex flex-col rounded-t-2xl bg-surface pb-[env(safe-area-inset-bottom)] text-primary-text shadow-2xl outline-none"
                    :class="[
                        height === 'half' ? 'h-[50dvh]' : 'h-[calc(100dvh_-_env(safe-area-inset-top)_-_0.75rem)]',
                        dragging ? '' : 'transition-[height,transform] duration-300 motion-reduce:transition-none',
                    ]"
                    :style="dragging ? { transform: `translateY(${offset}px)` } : undefined"
                >
                    <div
                        data-drag-handle
                        class="touch-none select-none"
                        @pointerdown="onPointerDown"
                        @pointermove="onPointerMove"
                        @pointerup="onPointerUp"
                        @pointercancel="resetDrag"
                    >
                        <button
                            v-if="adjustable"
                            data-grabber
                            type="button"
                            :aria-label="level === 0 ? 'Expand sheet' : 'Shrink sheet'"
                            class="flex h-5 w-full items-center justify-center"
                            @click="toggleHeight"
                        >
                            <span class="h-1.5 w-10 rounded-full bg-secondary-text/40" />
                        </button>
                        <div v-else data-grabber aria-hidden="true" class="flex h-5 items-center justify-center">
                            <span class="h-1.5 w-10 rounded-full bg-secondary-text/40" />
                        </div>
                        <header class="grid grid-cols-[1fr_auto_1fr] items-center gap-2 px-4 text-accent">
                            <button
                                data-cancel
                                type="button"
                                :disabled="cancelDisabled"
                                class="min-h-tap min-w-tap justify-self-start text-left disabled:opacity-40"
                                @click="emit('cancel')"
                            >
                                Cancel
                            </button>
                            <div class="text-center">
                                <h2 :id="titleId" class="font-semibold text-primary-text">{{ title }}</h2>
                                <p v-if="pending" class="text-xs text-secondary-text">Waiting to sync</p>
                            </div>
                            <button
                                v-if="saveTitle !== null"
                                data-save
                                type="button"
                                :disabled="saveDisabled || loading"
                                class="min-h-tap min-w-tap justify-self-end text-right font-semibold disabled:opacity-40"
                                @click="emit('save')"
                            >
                                {{ saveTitle }}
                            </button>
                            <span v-else aria-hidden="true" class="min-w-tap" />
                        </header>
                    </div>
                    <p v-if="error" role="alert" class="bg-tone-warning-bg px-4 py-2 text-sm text-tone-warning">{{ error }}</p>
                    <div
                        data-content
                        :aria-busy="loading ? 'true' : undefined"
                        class="min-h-0 flex-1 overflow-y-auto overscroll-contain border-t border-secondary-text/25"
                    >
                        <div v-if="loading" aria-hidden="true" class="flex flex-col gap-3 p-4">
                            <div v-for="n in 3" :key="n" class="h-tap animate-pulse rounded-xl bg-secondary-text/15" />
                        </div>
                        <slot v-else />
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
