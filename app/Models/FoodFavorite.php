<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Database\Factories\FoodFavoriteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A food the user starred (FOOD-023); it ranks first in their search. */
#[Fillable(['user_id', 'food_id'])]
class FoodFavorite extends Model
{
    /** @use HasFactory<FoodFavoriteFactory> */
    use BelongsToUser, HasFactory;

    /** @return BelongsTo<Food, $this> */
    public function food(): BelongsTo
    {
        return $this->belongsTo(Food::class);
    }
}
