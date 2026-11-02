<?php

namespace App\Enums;

/** Where a weigh-in (WT-003) or a day's steps (WT-020) came from: typed in, or posted by the Shortcuts bridge (INT-001). */
enum MeasurementSource: string
{
    case Manual = 'manual';
    case Shortcut = 'shortcut';
}
