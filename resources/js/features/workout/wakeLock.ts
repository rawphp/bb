// PWA-030: the screen wake lock an active workout holds, so the phone does not sleep
// between sets. The workout screen holds it while it is on show and under way, and lets it
// go at once on finish. When the user leaves the workout (another screen, another app) it
// keeps the hold for 30 minutes and then lets go; coming back within that time takes it
// again. The browser drops the lock while the page is hidden, so it is taken again on return.
// The lock is one workout's: another (an earlier one looked back at) never leaves or drops it.
export const LEAVE_AFTER_MS = 30 * 60_000;

let sentinel: WakeLockSentinel | null = null;
let wanted = false;
// The workout holding it.
let holder: string | null = null;
// The workout screen is on show; only then does a return to the page take the lock again.
let showing = false;
let timer: ReturnType<typeof setTimeout> | undefined;

async function acquire(): Promise<void> {
    if (sentinel || typeof navigator === 'undefined' || !('wakeLock' in navigator)) return;
    try {
        const lock = await navigator.wakeLock.request('screen');
        if (!wanted) {
            void lock.release();
            return;
        }
        sentinel = lock;
        lock.addEventListener('release', () => {
            if (sentinel === lock) sentinel = null;
        });
    } catch {
        // Refused (low battery, not visible): the workout works without it.
    }
}

function stopTimer(): void {
    clearTimeout(timer);
    timer = undefined;
}

/** The workout is on show and under way. */
export function holdWakeLock(workoutId: string): void {
    holder = workoutId;
    stopTimer();
    wanted = true;
    showing = true;
    void acquire();
}

/** The user left the workout: let go after 30 minutes unless they come back. */
export function leaveWakeLock(workoutId: string): void {
    if (holder !== workoutId) return;
    showing = false;
    startTimer();
}

function startTimer(): void {
    if (!wanted || timer !== undefined) return;
    timer = setTimeout(() => releaseWakeLock(), LEAVE_AFTER_MS);
}

/** The workout is finished, or (with no id) the leave ran out: let go now. */
export function releaseWakeLock(workoutId?: string): void {
    if (workoutId !== undefined && holder !== workoutId) return;
    holder = null;
    stopTimer();
    wanted = false;
    showing = false;
    const lock = sentinel;
    sentinel = null;
    void lock?.release().catch(() => undefined);
}

if (typeof document !== 'undefined') {
    document.addEventListener('visibilitychange', () => {
        if (!wanted) return;
        if (document.visibilityState === 'hidden') startTimer();
        else if (showing && holder !== null) holdWakeLock(holder);
    });
}
