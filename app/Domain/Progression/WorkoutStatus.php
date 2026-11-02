<?php

namespace App\Domain\Progression;

/**
 * Where a session stands (rp-clone WorkoutStatus). SCH-051: a row saved only to move or re-time a session has no
 * actual start, so it is not started.
 */
enum WorkoutStatus: string
{
    case NotStarted = 'not_started';
    case InProgress = 'in_progress';
    case Finished = 'finished';
    case Skipped = 'skipped';

    public static function of(bool $isSkipped, bool $hasStarted, bool $hasFinished): self
    {
        return match (true) {
            $isSkipped => self::Skipped,
            $hasFinished => self::Finished,
            $hasStarted => self::InProgress,
            default => self::NotStarted,
        };
    }

    /** rp-clone isReadOnly: a finished or skipped session takes no change. */
    public function isEditable(): bool
    {
        return $this !== self::Finished && $this !== self::Skipped;
    }

    /** Why a set or note can't be changed now; null while the session is under way. */
    public function refusal(): ?string
    {
        return match ($this) {
            self::InProgress => null,
            self::NotStarted => 'Start the workout first.',
            self::Finished => 'This workout is finished, so it can no longer be changed.',
            self::Skipped => 'This workout was skipped, so it can no longer be changed.',
        };
    }
}
