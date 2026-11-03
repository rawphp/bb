import { computed, ref } from 'vue';
import { owner } from '@/app/cache';

// NAV-004: the workout under way, so the bar above the tabs can open it again after the
// user leaves it. The workout screen keeps it current from what the server sends: it is
// set while workout_show has an active_bar_text and cleared when that workout's has none.
// Kept on the device, with whose it is, so a reload keeps the bar and another user never sees it.
export interface ActiveWorkout {
    user: string;
    workoutId: string;
    path: string;
    text: string;
}

const KEY = 'bb.workout.active';

function stored(): ActiveWorkout | null {
    try {
        const value = JSON.parse(localStorage.getItem(KEY) ?? 'null') as ActiveWorkout | null;
        return value && typeof value.path === 'string' && typeof value.text === 'string' ? value : null;
    } catch {
        return null;
    }
}

const current = ref<ActiveWorkout | null>(stored());

/** The signed-in user's workout under way, if any. */
export const activeWorkout = computed(() => (current.value && current.value.user === owner() ? current.value : null));

/** What the workout screen at `path` shows: its bar text, or null when it is not under way. */
export function showWorkout(workoutId: string, path: string, barText: string | null | undefined): void {
    const user = owner();
    if (barText) {
        if (user === null) return;
        current.value = { user, workoutId, path, text: barText };
        localStorage.setItem(KEY, JSON.stringify(current.value));
    } else if (current.value?.workoutId === workoutId) {
        current.value = null;
        localStorage.removeItem(KEY);
    }
}
