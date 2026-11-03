import { pendingInput } from '@/app/outbox';
import type { WorkoutFeedbackAnswerInput } from '@/contracts/workout_feedback_answer';
import type { WorkoutSetLogInput } from '@/contracts/workout_set_log';
import type { WorkoutSetSkipInput } from '@/contracts/workout_set_skip';
import type { WorkoutShowOutput } from '@/contracts/workout_show';

type Feedback = WorkoutShowOutput['feedback'][number];

/**
 * FB-001, OFF-020, OFF-021: a muscle's feedback falls due once every set of the exercise the server says it is asked
 * after is logged or skipped. The server's is_due stands for the sets it has seen. Offline, the sets the user logged,
 * unlogged or skipped since wait in the outbox: while one of that exercise's sets has a waiting change, the newest
 * waiting input of each set counts in place of the read. The server checks again when the answer replays, after those
 * sets (workout_feedback_answer). This mirrors App\Domain\Progression\FeedbackSheets, the one rule the client keeps
 * (docs/architecture.md, Client): a change to when a sheet falls due changes both. Between a replay dropping a sent
 * entry and the next read arriving, the stale read's is_due shows for a moment.
 */
export function isFeedbackDue(workout: WorkoutShowOutput, entry: Feedback): boolean {
    const sets = workout.exercises.find((exercise) => exercise.id === entry.due_after_exercise_id)?.sets ?? [];
    const waiting = sets.map((set) => ({
        set,
        log: pendingInput<WorkoutSetLogInput>('workout_set_log', (input) => input.set_id === set.id),
        skip: pendingInput<WorkoutSetSkipInput>('workout_set_skip', (input) => input.set_id === set.id),
    }));
    if (!waiting.some(({ log, skip }) => log !== undefined || skip !== undefined)) return entry.is_due;

    return waiting.every(({ set, log, skip }) => (log?.is_logged ?? set.is_logged) || (skip?.is_skipped ?? set.is_skipped));
}

/** OFF-009: the user's answer for this muscle waits to sync. */
export function isAnswerWaiting(workout: WorkoutShowOutput, muscle: string): boolean {
    return pendingInput<WorkoutFeedbackAnswerInput>('workout_feedback_answer', (input) => input.workout_id === workout.workout_id && input.muscle_group === muscle) !== undefined;
}
