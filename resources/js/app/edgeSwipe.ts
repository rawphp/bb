// Edge swipe back (PWA-022): where a swipe may start, and when letting go goes back.

/** Default width of the left-edge zone in CSS px. A route can narrow it (meta.edgeZone). */
export const EDGE_ZONE_PX = 24;

/** How long the screen takes to settle after the finger lifts, in ms. */
export const SETTLE_MS = 200;

/** A touch at `x` from the stack's left edge starts a swipe back if it is in the zone and there is a screen to go back to. */
export function startsSwipeBack(x: number, zone: number, depth: number): boolean {
    return depth > 1 && x >= 0 && x <= zone;
}

/** Letting go goes back past a third of the width, or on a quick flick (px per ms). */
export function swipeGoesBack(dx: number, width: number, velocity: number): boolean {
    return dx > width / 3 || (dx > 16 && velocity > 0.5);
}

/** How far a finger moves before a touch in the edge zone counts as a swipe or a scroll, in px. */
export const SLOP_PX = 8;

/** What a touch that started in the edge zone is, once it has moved by (dx, dy). */
export function swipeIntent(dx: number, dy: number): 'none' | 'swipe' | 'scroll' {
    if (Math.abs(dx) < SLOP_PX && Math.abs(dy) < SLOP_PX) return 'none';
    return dx > Math.abs(dy) ? 'swipe' : 'scroll';
}
