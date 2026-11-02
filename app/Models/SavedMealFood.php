<?php

namespace App\Models;

use Database\Factories\SavedMealFoodFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One food of a saved meal (MEAL-070), with the same fields as a planned food. */
#[Fillable(['saved_meal_id', 'food_id', 'position', 'grams', 'is_cooked', 'is_locked', 'unit'])]
class SavedMealFood extends Model
{
    /** @use HasFactory<SavedMealFoodFactory> */
    use HasFactory;

    protected $table = 'saved_meal_foods';

    public $timestamps = false;

    /** @return BelongsTo<SavedMeal, $this> */
    public function savedMeal(): BelongsTo
    {
        return $this->belongsTo(SavedMeal::class);
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
