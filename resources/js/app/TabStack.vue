<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { EDGE_ZONE_PX, SETTLE_MS, startsSwipeBack, swipeGoesBack, swipeIntent } from './edgeSwipe';
import { useNavigation } from './navigation';
import type { TabId } from './tabs';

// One tab's stack (PWA-020). Every screen in it stays mounted, so each keeps
// its scroll position; only the top one is interactive. Push and pop slide
// (PWA-021), and a swipe from the left edge goes back (PWA-022).
const props = defineProps<{ tab: TabId; active: boolean }>();

const nav = useNavigation();
const screens = computed(() => nav.state.stacks[props.tab]);
const root = ref<HTMLElement>();

const topScreen = () => root.value?.querySelector<HTMLElement>(':scope > [data-top]') ?? null;

watch(
    () => nav.state.scrollToTop,
    () => {
        if (!props.active) return;
        const reduce = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
        topScreen()?.scrollTo?.({ top: 0, behavior: reduce ? 'auto' : 'smooth' });
    },
);

// A touch in the edge zone is a candidate. It becomes a swipe on its first move
// that is clearly sideways; a tap or a vertical scroll goes to the screen as usual.
let touch: { x0: number; y0: number; t0: number; el: HTMLElement } | null = null;
let drag: { x0: number; t0: number; dx: number; width: number; el: HTMLElement; key?: number } | null = null;
let settling: ReturnType<typeof setTimeout> | undefined;

function onTouchStart(event: TouchEvent) {
    touch = null;
    const point = event.touches[0];
    if (!props.active || !root.value || !point || event.touches.length !== 1 || drag) return;
    const box = root.value.getBoundingClientRect();
    const zone = screens.value.at(-1)?.route.meta.edgeZone ?? EDGE_ZONE_PX;
    if (!startsSwipeBack(point.clientX - box.left, zone, screens.value.length)) return;
    const el = topScreen();
    if (el) touch = { x0: point.clientX, y0: point.clientY, t0: event.timeStamp, el };
}

function onTouchMove(event: TouchEvent) {
    const point = event.touches[0];
    if (!point) return;
    if (touch) {
        const intent = swipeIntent(point.clientX - touch.x0, point.clientY - touch.y0);
        if (intent === 'none') return;
        const { x0, t0, el } = touch;
        touch = null;
        if (intent === 'scroll') return;
        drag = { x0, t0, dx: 0, width: root.value?.getBoundingClientRect().width || window.innerWidth, el, key: screens.value.at(-1)?.key };
        el.style.transition = 'none';
    }
    if (!drag) return;
    // Claiming the move stops the browser's own swipe back from also firing.
    event.preventDefault();
    drag.dx = Math.max(0, point.clientX - drag.x0);
    drag.el.style.transform = `translateX(${drag.dx}px)`;
}

function release(el: HTMLElement) {
    el.style.transition = '';
    el.style.transform = '';
}

function onTouchEnd(event: TouchEvent) {
    touch = null;
    if (!drag) return;
    const { el, dx, width, t0, key } = drag;
    drag = null;
    const back = event.type === 'touchend' && swipeGoesBack(dx, width, dx / Math.max(1, event.timeStamp - t0));
    el.style.transition = `transform ${SETTLE_MS}ms ease-out`;
    el.style.transform = back ? 'translateX(100%)' : '';
    settling = setTimeout(() => {
        settling = undefined;
        // Go back only if the screen this swipe moved is still on top of the
        // shown tab; after a tab switch or a push in between, put it back.
        if (back && props.active && screens.value.length > 1 && screens.value.at(-1)?.key === key) {
            nav.back({ animate: false });
        } else {
            release(el);
        }
    }, SETTLE_MS);
}

onMounted(() => {
    const el = root.value!;
    el.addEventListener('touchstart', onTouchStart, { passive: true });
    el.addEventListener('touchmove', onTouchMove, { passive: false });
    el.addEventListener('touchend', onTouchEnd);
    el.addEventListener('touchcancel', onTouchEnd);
});

onBeforeUnmount(() => {
    const el = root.value!;
    el.removeEventListener('touchstart', onTouchStart);
    el.removeEventListener('touchmove', onTouchMove);
    el.removeEventListener('touchend', onTouchEnd);
    el.removeEventListener('touchcancel', onTouchEnd);
    clearTimeout(settling);
});
</script>

<template>
    <div
        ref="root"
        class="bb-tab-stack"
        :class="{ 'bb-tab-stack--active': active }"
        :data-tab="tab"
        :inert="!active"
        :aria-hidden="active ? undefined : 'true'"
    >
        <TransitionGroup name="bb-slide" :css="nav.state.animate">
            <section
                v-for="(screen, index) in screens"
                :key="screen.key"
                class="bb-screen"
                :data-top="index === screens.length - 1 ? '' : undefined"
                :inert="index < screens.length - 1"
                :aria-hidden="index < screens.length - 1 ? 'true' : undefined"
            >
                <RouterView :route="screen.route" />
            </section>
        </TransitionGroup>
    </div>
</template>
