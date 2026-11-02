import { useRouter } from 'vue-router';
import { findNavigation } from '@/app/navigation';

// What the plan screens share: which of the server's options shows as picked, and how a screen leaves.

/** The option the user picked, else the one the server marks, else the first. */
export function selectedIndex<T extends { is_selected: boolean }>(pick: number | null, list: readonly T[]): number {
    return pick ?? Math.max(0, list.findIndex((item) => item.is_selected));
}

/**
 * The tab stack's back. Opened on its own (a deep link, /dev/screens) there is no screen
 * under this one, so it goes to Train. No navigation outside the shell: dev screens and
 * tests render these with a plain router.
 */
export function useLeave(): () => void {
    const router = useRouter();
    const nav = findNavigation(router);

    return () => {
        if (nav && nav.state.stacks[nav.state.active].length > 1) nav.back();
        else void router.replace('/train');
    };
}
