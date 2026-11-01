<?php

namespace App\Domain\Plan;

use DomainException;

/** Thrown by a phase change that the phase rules refuse; the refusal says why. */
final class PhaseRefused extends DomainException
{
    public function __construct(public readonly PhaseRefusal $refusal)
    {
        parent::__construct("Phase refused: {$refusal->reason->value}");
    }
}
