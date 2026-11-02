<?php

namespace App\Domain\Mesocycle\MesoBuilder;

use App\Domain\Model\MuscleGroup;

/** PH-011: some muscles at Emphasize and these not, during Fat loss or Mini-cut. */
final readonly class SpecialisingInFatLoss implements Warning
{
    /** @param  list<MuscleGroup>  $notEmphasized  in MuscleGroup order */
    public function __construct(public array $notEmphasized) {}

    public function message(): string
    {
        return 'Specialising during a cut works against recovery';
    }
}
