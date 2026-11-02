import { computed, onBeforeUnmount, ref, watch, type Ref } from 'vue';

// How far a finger may drift before a touch becomes a swipe, so a tap still lands.
const SLOP = 10;
// How far a swipe must travel to reveal or hide the actions.
const THRESHOLD = 40;

/**
 * A row's swipe left that reveals actions behind it (UI-008), like SwiftUI's swipeActions
 * with allowsFullSwipe off: it never runs one by itself. Touch and pen only; a mouse uses the row's own
 * buttons. Once a drag is a swipe the row captures the pointer, so the drag never ends
 * in a click on what it started on. A tap anywhere outside `root`, or on the row, hides
 * the actions again, and so does `canSwipe` turning off.
 */
export function useSwipeReveal(root: Ref<HTMLElement | null>, trayWidth: () => number, canSwipe: () => boolean = () => true) {
    const open = ref(false);
    // While a finger drags the row: how far it has moved it.
    const drag = ref<number | null>(null);
    let start: { x: number; y: number; id: number; swiping: boolean } | null = null;

    const shift = computed(() => drag.value ?? (open.value ? -trayWidth() : 0));

    function pointerDown(event: PointerEvent): void {
        if (canSwipe() && event.pointerType !== 'mouse') start = { x: event.clientX, y: event.clientY, id: event.pointerId, swiping: false };
    }

    function pointerMove(event: PointerEvent): void {
        if (!start || event.pointerId !== start.id) return;
        const dx = event.clientX - start.x;
        if (!start.swiping) {
            if (Math.abs(dx) < SLOP || Math.abs(dx) <= Math.abs(event.clientY - start.y)) return;
            start.swiping = true;
            (event.currentTarget as HTMLElement).setPointerCapture?.(event.pointerId);
        }
        const base = open.value ? -trayWidth() : 0;
        drag.value = Math.min(0, Math.max(-trayWidth(), base + dx));
    }

    function pointerUp(event: PointerEvent): void {
        if (!start || event.pointerId !== start.id) return;
        if (start.swiping) {
            const dx = event.clientX - start.x;
            open.value = open.value ? dx < THRESHOLD : dx < -THRESHOLD;
        } else if (open.value) {
            open.value = false;
        }
        start = null;
        drag.value = null;
    }

    function pointerCancel(): void {
        start = null;
        drag.value = null;
    }

    function outside(event: PointerEvent): void {
        if (!root.value?.contains(event.target as Node)) open.value = false;
    }

    watch(open, (now) => {
        if (now) document.addEventListener('pointerdown', outside);
        else document.removeEventListener('pointerdown', outside);
    });
    watch(canSwipe, (can) => {
        if (!can) open.value = false;
    });
    onBeforeUnmount(() => document.removeEventListener('pointerdown', outside));

    return { open, drag, shift, pointerDown, pointerMove, pointerUp, pointerCancel };
}
