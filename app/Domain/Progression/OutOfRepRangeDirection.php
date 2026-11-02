<?php

namespace App\Domain\Progression;

/** PROG-053: which side of the rep range most sets fell on. */
enum OutOfRepRangeDirection: string
{
    case Below = 'below';
    case Above = 'above';
}
