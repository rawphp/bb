import { owner } from './cache';
import { writeNow } from './call';
import { now } from './clock';
import { announceReplayed } from './outbox';

// SET-012: the server counts the user's days in the device's time zone. The shell
// sends it on load, but only when the server may not have it: the first time, when
// the device lands in another zone, for another user, or once the last send is a day
// old, so a zone another device sent meanwhile is put right. Every send is audited
// (CAP-022), so this keeps them to about one a day per device.
//
// It is never kept in the outbox and a failure shows nothing: nothing is remembered,
// so the next load sends it again. A new zone can move today, so the screens showing
// a read it dropped load again.

const KEY = 'bb.time-zone-sent';
export const RESEND_MS = 86_400_000;

type Sent = { owner: string; zone: string; until: number };

export async function syncTimeZone(zone: string = Intl.DateTimeFormat().resolvedOptions().timeZone): Promise<void> {
    const user = owner();
    if (user !== null && isSent(user, zone)) return;

    try {
        const { invalidates } = await writeNow<{ invalidates: string[] }>('time_zone_set', { time_zone: zone });
        if (user !== null) remember({ owner: user, zone, until: now().getTime() + RESEND_MS });
        if (invalidates.length > 0) announceReplayed(invalidates);
    } catch {
        // Shows nothing; the next load sends it again.
    }
}

function isSent(user: string, zone: string): boolean {
    try {
        const sent = JSON.parse(localStorage.getItem(KEY) ?? 'null') as Partial<Sent> | null;

        return sent?.owner === user && sent.zone === zone && typeof sent.until === 'number' && now().getTime() < sent.until;
    } catch {
        return false;
    }
}

function remember(sent: Sent): void {
    try {
        localStorage.setItem(KEY, JSON.stringify(sent));
    } catch {
        // No local storage (a private window, say): the next load sends it again.
    }
}
