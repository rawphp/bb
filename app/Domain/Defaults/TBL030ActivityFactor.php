<?php

namespace App\Domain\Defaults;

use App\Domain\Defaults\TBL030ActivityFactor\Row;

/** TBL-030 Activity factor from planned daily steps. */
final class TBL030ActivityFactor
{
    /** @return list<Row> */
    public static function rows(): array
    {
        return [
            new Row(0, 4_999, 1.20),
            new Row(5_000, 7_999, 1.30),
            new Row(8_000, 9_999, 1.375),
            new Row(10_000, 12_999, 1.45),
            new Row(13_000, null, 1.55),
        ];
    }
}
