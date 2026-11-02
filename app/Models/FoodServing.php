<?php

namespace App\Models;

use Database\Factories\FoodServingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A named serving of a food, as-sold (raw) grams; liquids store ml as g. */
#[Fillable(['food_id', 'label', 'grams'])]
class FoodServing extends Model
{
    /** @use HasFactory<FoodServingFactory> */
    use HasFactory;

    public $timestamps = false;

    /** @return BelongsTo<Food, $this> */
    public function food(): BelongsTo
    {
        return $this->belongsTo(Food::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['grams' => 'float'];
    }
}
