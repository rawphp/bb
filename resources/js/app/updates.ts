import { computed, onScopeDispose, ref, toValue, watch, type MaybeRefOrGetter } from 'vue';
import { Workbox } from 'workbox-window';
import { anySheetOpen } from '../design/openSheets';

// Quiet updates (PWA-035). A new version installs in the background and waits.
// The banner offers it; only a tap reloads, and only the tab that was tapped.
// The banner stays away while any BottomSheet is open, and while a hold is open:
// the workout view (V_WO1) is to call useHoldUpdates() for the length of a workout
// (pwaWiring.test.ts fails once WorkoutView.vue exists without the call). A reload a
// tap started waits, too, if a workout or sheet opens before the new version is ready.
// Taking the worker off devices again: docs/service-worker.md.

let workbox: Workbox | null = null;
let registration: ServiceWorkerRegistration | undefined;
const waiting = ref(false);
const holds = ref(0);
// A tap was taken; the banner goes, and the reload follows once the new version is ready.
const applying = ref(false);
let reloading = false;

// Nothing on screen a reload would throw away.
const free = computed(() => holds.value === 0 && !anySheetOpen.value);

export const updateBanner = computed(() => waiting.value && free.value && !applying.value);

export function registerServiceWorker(): void {
    if (!('serviceWorker' in navigator)) return;

    workbox = new Workbox('/sw.js', { scope: '/' });
    workbox.addEventListener('waiting', () => (waiting.value = true));
    document.addEventListener('visibilitychange', () => {
        // Offline, the check fails; the next return to the foreground tries again.
        if (document.visibilityState === 'visible') workbox?.update().catch(() => undefined);
    });
    workbox
        .register()
        .then((found) => (registration = found))
        .catch((error: unknown) => console.error('The service worker did not register', error));
}

/** Hides the update banner, and holds back a reload, until the returned release is called. */
export function holdUpdates(): () => void {
    holds.value++;
    let held = true;

    return () => {
        if (!held) return;
        held = false;
        holds.value--;
    };
}

/**
 * Holds updates while `active` is true, from a component's setup or any effect scope.
 * The hold is released when `active` turns false and when the component unmounts, so a
 * screen left by any path never keeps the banner away.
 */
export function useHoldUpdates(active: MaybeRefOrGetter<boolean> = true): void {
    let release: (() => void) | null = null;
    const stop = watch(
        () => toValue(active),
        (on) => {
            if (on && !release) release = holdUpdates();
            else if (!on && release) {
                release();
                release = null;
            }
        },
        { immediate: true, flush: 'sync' },
    );
    onScopeDispose(() => {
        stop();
        release?.();
    });
}

export function applyUpdate(): void {
    if (!workbox || !updateBanner.value) return;
    applying.value = true;

    // No worker waiting any more: another tab already activated the new version.
    if (!registration?.waiting) {
        reloadWhenFree();
        return;
    }
    // A tab the old worker controls reloads when the new one takes control of it. A tab no
    // worker controls yet (opened before the first worker activated, since sw.ts never claims
    // tabs) never sees that, so it reloads once the new version has activated.
    if (navigator.serviceWorker.controller) {
        workbox.addEventListener('controlling', reloadWhenFree);
    } else {
        const next = registration.waiting;
        next.addEventListener('statechange', () => {
            if (next.state === 'activated') reloadWhenFree();
        });
    }
    workbox.messageSkipWaiting();
}

// Reloads now, or as soon as the last hold is released and the last sheet closes.
function reloadWhenFree(): void {
    if (reloading) return;
    reloading = true;
    if (free.value) {
        window.location.reload();
        return;
    }
    const stop = watch(
        free,
        (isFree) => {
            if (!isFree) return;
            stop();
            window.location.reload();
        },
        { flush: 'sync' },
    );
}
