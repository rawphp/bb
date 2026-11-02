<?php

namespace App\Domain\Macros;

/** TBL-032: carbs outside the sanity bounds for the day type. A warning only, never a different split. */
enum CarbBoundsWarning: string
{
    case Below = 'below';
    case Above = 'above';
}
