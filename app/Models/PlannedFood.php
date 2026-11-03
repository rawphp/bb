<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Database\Factories\PlannedFoodFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A food planned in a meal (MEAL-001 to MEAL-008, FOOD-009). */
#[Fillable(['user_id', 'meal_id', 'food_id', 'position', 'grams', 'is_cooked', 'is_locked', 'unit'])]
class PlannedFood extends Model
{
    /** @use HasFactory<PlannedFoodFactory> */
    use BelongsToUser, HasFactory, HasUlids;

    protected $table = 'planned_foods';

    /** @return BelongsTo<DayTargetMeal, $this> */
    public function meal(): BelongsTo
    {
        return $this->belongsTo(DayTargetMeal::class, 'meal_id');
    }

    /** @return BelongsTo<Food, $this> */
    public function food(): BelongsTo
    {
        return $this->belongsTo(Food::class)->withTrashed();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'grams' => 'float',
            'is_cooked' => 'boolean',
            'is_locked' => 'boolean',
        ];
    }
}
