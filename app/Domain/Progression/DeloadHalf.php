<?php

namespace App\Domain\Progression;

/** PROG-041: the half of a deload week a session falls in. */
enum DeloadHalf: string
{
    case First = 'first';
    case Second = 'second';
}
