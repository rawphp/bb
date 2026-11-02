<?php

namespace App\Domain\Progression;

/** What happened to one muscle's sets in the next occurrence of a session. */
final readonly class MuscleOutcome
{
    /**
     * @param  int  $change  net sets added (positive) or removed (negative) in this session
     * @param  bool  $atRecoveryLimit  PROG-015: the MRV cut a wanted add
     * @param  ?string  $recoveryMessage  "At your recovery limit for {muscle}" when $atRecoveryLimit
     * @param  bool  $suggestsAddingExercise  PROG-017: an add had no place to go and every exercise for the muscle is at 5 sets
     * @param  list<string>  $loadCutSlotIds  PROG-018: slots with "A lot" of pain; cut their next load by LOAD_CUT_PERCENT
     */
    public function __construct(
        public int $change,
        public bool $atRecoveryLimit,
        public ?string $recoveryMessage,
        public bool $suggestsAddingExercise,
        public array $loadCutSlotIds,
    ) {}
}
