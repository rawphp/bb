<?php

namespace App\Capabilities\WeighIns\Data;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Date;

/**
 * The date-time format check is a pattern, so 30 February or 25:00 would pass it and roll over to another day when
 * parsed. A weigh-in's time must be one the calendar has, inside what the weigh_ins timestamp column holds.
 */
final class WeighInTimeRule
{
    public static function make(): Date
    {
        return Rule::date()->afterOrEqual('1970-01-02')->beforeOrEqual('2038-01-18');
    }
}
