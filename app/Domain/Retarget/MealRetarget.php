<?php

namespace App\Domain\Retarget;

/** SCH-056: the re-targeted days, the same days as they were for Undo, and the toast. */
final readonly class MealRetarget
{
    /**
     * @param  list<RetargetDay>  $days
     * @param  list<RetargetDay>  $undo
     */
    public function __construct(
        public array $days,
        public array $undo,
        public string $message,
    ) {}
}
