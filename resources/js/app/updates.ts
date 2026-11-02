import { computed, ref } from 'vue';
import { Workbox } from 'workbox-window';
import { anySheetOpen } from '../design/openSheets';

// Quiet updates (PWA-035). A new version installs in the background and waits.
// The banner offers it; only a tap reloads, and only the tab that was tapped.
// The banner stays away while any BottomSheet is open, and while a hold is open:
// the workout view (V_WO1) is to call holdUpdates() for the length of a workout.

let workbox: Workbox | null = null;
let registration: ServiceWorkerRegistration | undefined;
const waiting = ref(false);
const holds = ref(0);

export const updateBanner = computed(() => waiting.value && holds.value === 0 && !anySheetOpen.value);

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

/** Hides the update banner until the returned release is called. */
export function holdUpdates(): () => void {
    holds.value++;
    let held = true;

    return () => {
        if (!held) return;
        held = false;
        holds.value--;
    };
}

export function applyUpdate(): void {
    if (!workbox || !updateBanner.value) return;

    // No worker waiting any more: another tab already activated the new version.
    if (!registration?.waiting) {
        window.location.reload();
        return;
    }
    workbox.addEventListener('controlling', () => window.location.reload());
    workbox.messageSkipWaiting();
}
