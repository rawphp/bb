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
