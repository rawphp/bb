import { onBeforeUnmount } from 'vue';

// How long a finger must stay down, and how far it may drift, for a press to be long.
const HOLD_MS = 500;
const SLOP = 10;

/**
 * A long press with a finger or a pen, which opens a card's menu (SCH-010), as SwiftUI's
 * contextMenu does. iPhone Safari sends no contextmenu for one, so the card times the press
 * itself; a mouse keeps its right click (contextmenu). A finger that moves is a swipe or a
 * scroll, not a press. The click that ends a long press reaches nothing inside the card:
 * bind `click` in the capture phase.
 */
export function useLongPress(onPress: () => void) {
    let timer: ReturnType<typeof setTimeout> | undefined;
    let start: { x: number; y: number; id: number } | null = null;
    let pressed = false;

    function cancel(): void {
        clearTimeout(timer);
        timer = undefined;
        start = null;
    }

    function pointerDown(event: PointerEvent): void {
        pressed = false;
        cancel();
        if (event.pointerType === 'mouse') return;
        start = { x: event.clientX, y: event.clientY, id: event.pointerId };
        timer = setTimeout(() => {
            cancel();
            pressed = true;
            onPress();
        }, HOLD_MS);
    }

    function pointerMove(event: PointerEvent): void {
        if (start && event.pointerId === start.id && Math.hypot(event.clientX - start.x, event.clientY - start.y) > SLOP) cancel();
    }

    function click(event: MouseEvent): void {
        if (!pressed) return;
        pressed = false;
        event.preventDefault();
        event.stopImmediatePropagation();
    }

    onBeforeUnmount(cancel);

    return {
        pointerDown,
        pointerMove,
        pointerEnd: cancel,
        click,
        /** Every listener at once, for an element with no pointer handling of its own: `v-bind="press.listeners"`. */
        listeners: {
            onPointerdown: pointerDown,
            onPointermove: pointerMove,
            onPointerup: cancel,
            onPointercancel: cancel,
            onClickCapture: click,
        },
    };
}
