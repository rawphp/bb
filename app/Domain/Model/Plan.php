<?php

namespace App\Domain\Model;

use DateTimeImmutable;

/** The user's plan: phases laid on the plan-week grid (PLAN-008). */
final readonly class Plan
{
    /**
     * @param  int  $reviewWeekday  the review day the plan started on (1 = Sunday ... 7 = Saturday), also the first day
     *                              of every plan week until a change (ONB-016)
     * @param  list<ReviewDayChange>  $reviewDayChanges  in date order (PLAN-008)
     * @param  list<Phase>  $phases
     */
    public function __construct(
        public string $id,
        public DateTimeImmutable $start,
        public int $reviewWeekday,
        public array $phases,
        public array $reviewDayChanges = [],
    ) {}

    /**
     * The review day the user chose last: the last change's weekday, even before that change's `from` date, as
     * rp-clone's PLAN-008 rules expect straight after a change. Which day starts a given week is the plan-week grid's
     * answer, from each change's `from`.
     */
    public function currentReviewWeekday(): int
    {
        $changes = $this->reviewDayChanges;

        return $changes === [] ? $this->reviewWeekday : $changes[array_key_last($changes)]->weekday;
    }
}
