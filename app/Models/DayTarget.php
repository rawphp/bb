<?php

namespace App\Models;

use App\Domain\Model\DayType;
use App\Models\Concerns\BelongsToUser;
use Database\Factories\DayTargetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * A day's targets and its meals' targets (NUT-030). Each meal in `meals` carries an id,
 * and day_target_meals indexes those ids so foods, check-ins and planning state can point at a meal.
 * A soft delete leaves the index and everything on it, so a restore brings the day back whole;
 * a force delete cascades through the foreign keys.
 *
 * @property list<array<string, mixed>> $meals
 */
#[Fillable(['date', 'day_type', 'kcal', 'protein_g', 'fat_g', 'carbs_g', 'meals'])]
class DayTarget extends Model
{
    /** @use HasFactory<DayTargetFactory> */
    use BelongsToUser, HasFactory, HasUuids, SoftDeletes;

    /**
     * A meal keeps the id it has, gets one if it has none, and the index follows. Meals sent with
     * no ids at all rewrite the day's meals in place: meal N keeps the id of the day's meal N, so a
     * save that doesn't echo ids keeps the foods and check-ins on the meals it keeps.
     */
    protected static function booted(): void
    {
        static::saving(function (self $target) {
            $current = $target->exists && array_filter(array_column($target->meals, 'id')) === []
                ? $target->mealIndex()->orderBy('position')->pluck('id')->all()
                : [];
            $target->meals = array_map(
                fn (array $meal, int $position) => ['id' => strtolower($meal['id'] ?? $current[$position] ?? (string) Str::uuid7())] + $meal,
                $target->meals,
                array_keys($target->meals),
            );
            $target->refuseTakenMealIds();
        });

        static::saved(function (self $target) {
            $ids = array_column($target->meals, 'id');
            $indexed = $target->mealIndex()->pluck('position', 'id')->all();

            // Removing a row cascades to the meal's planned foods, recipes, check-in and planning state.
            $target->mealIndex()->whereNotIn('id', $ids)->delete();
            foreach ($ids as $position => $id) {
                if (! array_key_exists($id, $indexed)) {
                    // An insert, not an upsert: an id another day holds fails on the primary key
                    // rather than moving that day's meal here.
                    DayTargetMeal::insert(['id' => $id, 'user_id' => $target->user_id, 'day_target_id' => $target->id, 'position' => $position]);
                } elseif ((int) $indexed[$id] !== $position) {
                    $target->mealIndex()->whereKey($id)->update(['position' => $position]);
                }
            }
        });
    }

    /**
     * A meal id belongs to one meal of one day. An id used twice in this day, or already
     * indexed under another day target (any user's, a soft-deleted one included), is refused, so the
     * upsert never moves or re-points another day's meal.
     */
    private function refuseTakenMealIds(): void
    {
        $ids = array_column($this->meals, 'id');
        $taken = DayTargetMeal::whereIn('id', $ids)
            ->when($this->exists, fn ($query) => $query->where('day_target_id', '!=', $this->getKey()))
            ->pluck('id')
            ->all();

        foreach ($ids as $index => $id) {
            if (in_array($id, $taken, true) || array_search($id, $ids, true) !== $index) {
                throw ValidationException::withMessages(["meals.{$index}.id" => 'This meal id is already used by another meal.']);
            }
        }
    }

    /**
     * The row and its meal index are written together, or neither is.
     *
     * @param  array<string, mixed>  $options
     */
    public function save(array $options = []): bool
    {
        return DB::transaction(fn () => parent::save($options));
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
