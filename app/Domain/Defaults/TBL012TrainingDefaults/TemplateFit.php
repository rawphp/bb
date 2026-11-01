<?php

namespace App\Domain\Defaults\TBL012TrainingDefaults;

enum TemplateFit: string
{
    case FullBody = 'fullBody';
    case FullBodyTwoToThreeDays = 'fullBodyTwoToThreeDays';
    case Any = 'any';
    case Specialisation = 'specialisation';
}
