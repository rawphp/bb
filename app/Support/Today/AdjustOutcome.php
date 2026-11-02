<?php

namespace App\Support\Today;

use App\Domain\Model\MealTarget;
use DateTimeImmutable;

/**
 * What the Adjust sheet's choices come to (DAY-020): still incomplete (with what is missing), refused (with why), or
 * the days the move changes, the selected day first.
 */
final readonly class AdjustOutcome
{
    /**
     * @param  list<array{date: DateTimeImmutable, target: MealTarget, meals: array<string, MealTarget>}>  $changed
     *                                                                                                               each changed day's totals and every meal's target, by id
     */
    private function __construct(
        public ?string $missing,
        public ?string $refusal,
        public array $changed,
    ) {}

    public static function incomplete(string $missing): self
    {
        return new self($missing, null, []);
    }

    public static function refused(string $refusal): self
    {
        return new self(null, $refusal, []);
    }

    /** @param  list<array{date: DateTimeImmutable, target: MealTarget, meals: array<string, MealTarget>}>  $changed */
    public static function plan(array $changed): self
    {
        return new self(null, null, $changed);
    }

    public function isPlan(): bool
    {
        return $this->changed !== [];
    }
}
