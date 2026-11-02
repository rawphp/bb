<?php

namespace App\Domain\Retarget;

use DomainException;

/** Thrown by a re-target that the rules refuse; the refusal says why. */
final class RetargetRefused extends DomainException
{
    public function __construct(public readonly RetargetRefusal $refusal)
    {
        parent::__construct("Retarget refused: {$refusal->value}");
    }
}
