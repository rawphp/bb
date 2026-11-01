<?php

namespace App\Models;

use App\Enums\DayType;
use App\Models\Concerns\BelongsToUser;
use Database\Factories\DayTargetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * A day's targets and its meals' targets (NUT-030). Each meal in `meals` carries an id,
 * and day_target_meals indexes those ids so foods, check-ins and planning state can point at a meal.
 *
 * @property list<array<string, mixed>> $meals
 */
#[Fillable(['date', 'day_type', 'kcal', 'protein_g', 'fat_g', 'carbs_g', 'meals'])]
class DayTarget extends Model
{
    /** @use HasFactory<DayTargetFactory> */
    use BelongsToUser, HasFactory, HasUuids, SoftDeletes;

    /** A meal keeps the id it has, gets one if it has none, and the index follows. */
    protected static function booted(): void
    {
        static::saving(function (self $target) {
            $target->meals = array_map(
                fn (array $meal) => ['id' => strtolower($meal['id'] ?? (string) Str::uuid7())] + $meal,
                $target->meals,
            );
        });

        static::saved(function (self $target) {
            $ids = array_column($target->meals, 'id');

            // Removing a row cascades to the meal's planned foods, recipes, check-in and planning state.
            $target->mealIndex()->whereNotIn('id', $ids)->delete();
            DayTargetMeal::upsert(
                array_map(fn ($id, $position) => [
                    'id' => $id, 'user_id' => $target->user_id, 'day_target_id' => $target->id, 'position' => $position,
                ], $ids, array_keys($ids)),
                ['id'],
                ['position'],
            );
        });

        static::deleted(fn (self $target) => $target->mealIndex()->delete());
    }

    /** @return HasMany<DayTargetMeal, $this> */
    public function mealIndex(): HasMany
    {
        return $this->hasMany(DayTargetMeal::class);
    }

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
            'day_type' => DayType::class,
            'kcal' => 'float',
            'protein_g' => 'float',
            'fat_g' => 'float',
            'carbs_g' => 'float',
            'meals' => 'array',
        ];
    }
}
