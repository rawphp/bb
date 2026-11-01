import { ref } from 'vue';

// The client's one clock (SET-011, architecture.md "Walking through time"):
// the device clock plus an offset kept in local storage. With an offset set,
// call.ts sends X-BB-Now, which the server honours in local and testing only.

const KEY = 'bb.clock-offset';
const DAY_MS = 86_400_000;

const offsetMs = ref(stored());

export function now(): Date {
    return new Date(Date.now() + offsetMs.value);
}

export function isWalked(): boolean {
    return offsetMs.value !== 0;
}

export function walkDays(days: number): void {
    save(offsetMs.value + days * DAY_MS);
}

// A date picker value, yyyy-mm-dd, kept at the current time of day.
export function walkToDate(value: string): void {
    const [year, month, day] = value.split('-').map(Number);
    if (!year || !month || !day) return;

    const target = now();
    target.setFullYear(year, month - 1, day);
    save(target.getTime() - Date.now());
}

export function clearWalk(): void {
    save(0);
}

function save(ms: number): void {
    offsetMs.value = ms;
    if (ms === 0) localStorage.removeItem(KEY);
    else localStorage.setItem(KEY, String(ms));
}

function stored(): number {
    const ms = Number(localStorage.getItem(KEY));

    return Number.isFinite(ms) ? ms : 0;
}
