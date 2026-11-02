<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Database\Factories\SavedMealFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A meal's foods saved under a name for reuse (MEAL-070, MEAL-071). */
#[Fillable(['user_id', 'name'])]
class SavedMeal extends Model
{
    /** @use HasFactory<SavedMealFactory> */
    use BelongsToUser, HasFactory, HasUlids;

    /** @return HasMany<SavedMealFood, $this> */
    public function foods(): HasMany
    {
        return $this->hasMany(SavedMealFood::class)->orderBy('position');
    }
}
