<?php

namespace App\Domain\Plan;

use DomainException;

/** Thrown when the targets cannot be rebuilt; the message is what the user reads, the refusal says why. */
final class PlanRebuildRefused extends DomainException
{
    public function __construct(public readonly PlanRebuildRefusal $refusal)
    {
        parent::__construct($refusal->message());
    }
}
