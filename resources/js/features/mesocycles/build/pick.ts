/**
 * A picker on the build flow always shows what the server last said. On a change it goes
 * back to `saved` at once, so a refused save leaves nothing picked that was never saved,
 * and the same pick sends again. Returns the pick when it differs from `saved`, else null.
 */
export function takePick(event: Event, saved: string): string | null {
    const field = event.target as HTMLInputElement | HTMLSelectElement;
    const picked = field.value;
    field.value = saved;

    return picked === saved ? null : picked;
}
