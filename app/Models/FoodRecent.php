<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Database\Factories\FoodRecentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The last time the user planned, checked in or logged a food: one row per food (FOOD-023). */
#[Fillable(['user_id', 'food_id', 'used_at'])]
class FoodRecent extends Model
{
    /** @use HasFactory<FoodRecentFactory> */
    use BelongsToUser, HasFactory;

    public $timestamps = false;

    /** @return BelongsTo<Food, $this> */
    public function food(): BelongsTo
    {
        return $this->belongsTo(Food::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['used_at' => 'datetime'];
    }
}
