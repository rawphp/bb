import type { CapabilityInput } from '@/app/call';

/**
 * A change a row or card asks the workout screen to send through the outbox (OFF-006):
 * the write and its input as the user made it, its outbox label (OFF-010), the rows it
 * creates (CAP-019), a question to ask first for what can't be undone, and whether it
 * taps the phone (PWA-033).
 */
export interface WorkoutWrite {
    name: string;
    input: CapabilityInput;
    label: string;
    creates?: string[];
    confirm?: { title: string; message: string; action: string };
    haptic?: boolean;
}

/**
 * A sheet a row or card asks the workout screen to open (it owns which one is open): the
 * ids it needs, which the sheet looks up in the workout it is handed.
 */
export type WorkoutSheet =
    | { name: 'set-menu'; setId: string }
    | { name: 'rir' }
    | { name: 'swap'; exerciseId: string }
    | { name: 'note'; exerciseId: string; pinned: boolean }
    | { name: 'feedback'; muscle: string }
    | { name: 'look-back' }
    | { name: 'help' }
    | { name: 'add-exercise' }
    | { name: 'reorder' }
    | { name: 'relabel' };
