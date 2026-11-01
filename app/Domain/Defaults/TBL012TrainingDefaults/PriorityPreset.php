<?php

namespace App\Domain\Defaults\TBL012TrainingDefaults;

enum PriorityPreset: string
{
    case AllEmphasize = 'allEmphasize';
    case AllMaintain = 'allMaintain';
    case AllGrow = 'allGrow';
    case UpToTwoEmphasizeRestGrowOrMaintain = 'upToTwoEmphasizeRestGrowOrMaintain';
}
