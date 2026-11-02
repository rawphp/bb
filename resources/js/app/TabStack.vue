<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { EDGE_ZONE_PX, SETTLE_MS, startsSwipeBack, swipeGoesBack } from './edgeSwipe';
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

let drag: { x0: number; t0: number; dx: number; width: number; el: HTMLElement } | null = null;

function onTouchStart(event: TouchEvent) {
    const touch = event.touches[0];
    if (!props.active || !root.value || !touch || event.touches.length !== 1) return;
    const box = root.value.getBoundingClientRect();
    const zone = screens.value.at(-1)?.route.meta.edgeZone ?? EDGE_ZONE_PX;
    if (!startsSwipeBack(touch.clientX - box.left, zone, screens.value.length)) return;
    const el = topScreen();
    if (!el) return;
    // Claiming the touch stops the browser's own swipe back from also firing.
    event.preventDefault();
    drag = { x0: touch.clientX, t0: event.timeStamp, dx: 0, width: box.width || window.innerWidth, el };
    el.style.transition = 'none';
}

function onTouchMove(event: TouchEvent) {
    const touch = event.touches[0];
    if (!drag || !touch) return;
    event.preventDefault();
    drag.dx = Math.max(0, touch.clientX - drag.x0);
    drag.el.style.transform = `translateX(${drag.dx}px)`;
}

function onTouchEnd(event: TouchEvent) {
    if (!drag) return;
    const { el, dx, width, t0 } = drag;
    drag = null;
    const back = event.type === 'touchend' && swipeGoesBack(dx, width, dx / Math.max(1, event.timeStamp - t0));
    el.style.transition = `transform ${SETTLE_MS}ms ease-out`;
    el.style.transform = back ? 'translateX(100%)' : '';
    setTimeout(() => {
        if (back) {
            nav.back({ animate: false });
        } else {
            el.style.transition = '';
        }
    }, SETTLE_MS);
}

onMounted(() => {
    const el = root.value!;
    el.addEventListener('touchstart', onTouchStart, { passive: false });
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
