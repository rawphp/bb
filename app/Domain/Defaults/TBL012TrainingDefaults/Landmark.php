<?php

namespace App\Domain\Defaults\TBL012TrainingDefaults;

/** The TBL-021 volume landmark a start volume or ceiling is measured from. */
enum Landmark: string
{
    case Mv = 'mv';
    case Mev = 'mev';
    case Mrv = 'mrv';
}
