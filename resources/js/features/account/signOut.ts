import { endSession } from '@/app/call';
import { usePush } from '@/features/notifications/usePush';

// Sign out of this browser (ACC-006). Push is turned off first, while the
// session can still call push_unsubscribe, so a shared browser stops getting
// the old account's notifications (NOTIF-010). Every sign-out control calls this.
export async function signOut(push: Pick<ReturnType<typeof usePush>, 'disable'> = usePush()): Promise<void> {
    try {
        await push.disable();
    } catch {
        // disable() has already unsubscribed the browser; its row goes on the next send.
    }

    await endSession();
}
