<?php

namespace App\Domain\Mesocycle\MesoBuilder;

/** PH-010: more than 2 muscles at Emphasize during Muscle gain. */
final readonly class TooManyEmphasizedInMuscleGain implements Warning
{
    public function __construct(public int $count) {}

    public function message(): string
    {
        return 'Emphasizing more than 2 muscles at once spreads your recovery thin';
    }
}
