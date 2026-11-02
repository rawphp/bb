<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Database\Factories\WeighInScheduleEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** One weigh-in schedule entry: weekdays with a time for the reminder (WT-001, WT-007). */
#[Fillable(['weekdays', 'time'])]
class WeighInScheduleEntry extends Model
{
    /** @use HasFactory<WeighInScheduleEntryFactory> */
    use BelongsToUser, HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['weekdays' => 'array'];
    }
}
