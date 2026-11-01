<?php

namespace App\Domain\Model;

/** One muscle's feedback sheet (FB-001). Soreness is null when it was not asked (FB-007). */
final readonly class FeedbackAnswers
{
    /**
     * @param  array<string, JointPain>  $jointPain  joint pain per exercise slot id
     */
    public function __construct(
        public array $jointPain = [],
        public ?Soreness $soreness = null,
        public ?Pump $pump = null,
        public ?VolumeFeedback $volume = null,
    ) {}
}
