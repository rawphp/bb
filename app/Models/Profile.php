<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Database\Factories\ProfileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * The user's profile and settings, SET-001 to SET-006. One per user.
 * Height and weight are stored in metric; weight_unit and height_unit only choose how they are shown (SET-001).
 * targets_rebuild_failed_at is set while a rebuild that failed after a save is pending (SET-004).
 */
#[Fillable([
    'name', 'sex', 'birth_date', 'height_cm', 'weight_kg', 'body_fat_bucket', 'uses_glp1', 'experience',
    'weight_unit', 'height_unit', 'appearance', 'consumed_totals', 'micro_plates',
    'eating_window', 'meal_count', 'busy_periods', 'training_days', 'weigh_in_days', 'step_plan',
    'diet_filters', 'screening', 'training_only', 'targets_rebuild_failed_at',
])]
class Profile extends Model
{
    /** @use HasFactory<ProfileFactory> */
    use BelongsToUser, HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'birth_date' => 'date:Y-m-d',
            'height_cm' => 'float',
            'weight_kg' => 'float',
            'uses_glp1' => 'boolean',
            'micro_plates' => 'boolean',
            'meal_count' => 'integer',
            'eating_window' => 'array',
            'busy_periods' => 'array',
            'training_days' => 'array',
            'weigh_in_days' => 'array',
            'step_plan' => 'array',
            'diet_filters' => 'array',
            'screening' => 'array',
            'training_only' => 'boolean',
            'targets_rebuild_failed_at' => 'datetime',
        ];
    }
}
