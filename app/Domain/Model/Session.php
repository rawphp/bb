<?php

namespace App\Domain\Model;

/** A planned training session: an ordered list of exercise slots. */
final readonly class Session
{
    /**
     * @param  list<ExerciseSlot>  $slots
     */
    public function __construct(
        public string $id,
        public string $name,
        public array $slots,
    ) {}
}
