<?php

namespace App\Domain\Onboarding;

/**
 * One block of the review (ONB-018). `editStep` is the step its Edit link goes back to; null where that step is
 * skipped (training-only skips step 6).
 */
final readonly class ReviewBlock
{
    /** @param  list<ReviewRow>  $rows */
    public function __construct(
        public string $title,
        public ?int $editStep,
        public array $rows,
    ) {}
}
