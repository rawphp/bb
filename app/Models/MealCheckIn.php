<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Database\Factories\MealCheckInFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** What the user ate in a meal when they checked it in (MEAL-040 to MEAL-047). One per meal. */
#[Fillable(['user_id', 'meal_id', 'time', 'kcal', 'protein_g', 'fat_g', 'carbs_g', 'is_manual'])]
class MealCheckIn extends Model
{
    /** @use HasFactory<MealCheckInFactory> */
    use BelongsToUser, HasFactory, HasUlids;

    protected $table = 'meal_check_ins';

    /** @return BelongsTo<DayTargetMeal, $this> */
    public function meal(): BelongsTo
    {
        return $this->belongsTo(DayTargetMeal::class, 'meal_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kcal' => 'float',
            'protein_g' => 'float',
            'fat_g' => 'float',
            'carbs_g' => 'float',
            'is_manual' => 'boolean',
        ];
    }
}
