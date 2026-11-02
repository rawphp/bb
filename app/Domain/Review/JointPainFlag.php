<?php

namespace App\Domain\Review;

use App\Domain\Model\JointPain;

/** A joint-pain answer worth showing (FB-002): the exercise and the worst level answered for it. */
final readonly class JointPainFlag
{
    public function __construct(
        public string $exerciseName,
        public JointPain $level,
    ) {}
}
