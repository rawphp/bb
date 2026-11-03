<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Database\Factories\PlanWeekFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A plan week (PLAN-008) flagged as a deload, diet break, away week (SCH-060) or rough week (SCH-063), and when its review
 * was saved (REV-001). An away week keeps whether it was saved keeping the phase end date (PLAN-025).
 */
#[Fillable(['start_date', 'is_deload', 'is_diet_break', 'diet_break_off', 'is_away', 'away_training', 'away_calories', 'is_rough', 'keep_phase_end', 'reviewed_at'])]
class PlanWeek extends Model
{
    /** @use HasFactory<PlanWeekFactory> */
    use BelongsToUser, HasFactory, HasUuids, SoftDeletes;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'start_date' => 'date:Y-m-d',
            'is_deload' => 'boolean',
            'is_diet_break' => 'boolean',
            'diet_break_off' => 'boolean',
            'is_away' => 'boolean',
            'is_rough' => 'boolean',
            'keep_phase_end' => 'boolean',
            'reviewed_at' => 'datetime',
        ];
    }
}
