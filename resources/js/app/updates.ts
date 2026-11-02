import { computed, ref } from 'vue';
import { Workbox } from 'workbox-window';

// Quiet updates (PWA-035). A new version installs in the background and waits.
// The banner offers it; only a tap reloads, and only the tab that was tapped.
// A workout or an open sheet calls holdUpdates() to keep the banner away.

let workbox: Workbox | null = null;
const waiting = ref(false);
const holds = ref(0);
// Another tab already activated the new version; this tab still runs the old one.
let activated = false;

export const updateBanner = computed(() => waiting.value && holds.value === 0);

export function registerServiceWorker(): void {
    if (!('serviceWorker' in navigator)) return;

    workbox = new Workbox('/sw.js', { scope: '/' });
    workbox.addEventListener('waiting', () => (waiting.value = true));
    workbox.addEventListener('controlling', () => (activated = true));
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') void workbox?.update();
    });
    void workbox.register();
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

    if (activated) {
        window.location.reload();
        return;
    }
    workbox.addEventListener('controlling', () => window.location.reload());
    workbox.messageSkipWaiting();
}
