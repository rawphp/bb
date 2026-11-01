<?php

namespace App\Domain\Model;

/** Training experience (ONB-010). */
enum ExperienceLevel: string
{
    case Beginner = 'beginner';
    case Intermediate = 'intermediate';
    case Advanced = 'advanced';
}
