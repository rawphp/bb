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
     * A meal keeps the id it has, and the index follows. A new day's meals get ids if they have
     * none. Updating a day needs every meal's id (a new meal brings its own), because a meal left
     * out is removed with its foods and check-in.
     */
    protected static function booted(): void
    {
        static::saving(function (self $target) {
            $target->refuseMealsWithoutIds();
            $target->meals = array_map(
                fn (array $meal) => ['id' => strtolower($meal['id'] ?? (string) Str::uuid7())] + $meal,
                $target->meals,
            );
            $target->refuseTakenMealIds();
        });

        // Through DayTargetMeal models, so a reversible write's undo sees each index change (CAP-013).
        static::saved(function (self $target) {
            $ids = array_column($target->meals, 'id');
            $indexed = $target->mealIndex()->get()->keyBy('id');

            // Removing a meal removes its planned foods, recipes, check-in and planning state.
            $indexed->except($ids)->each->delete();
            foreach ($ids as $position => $id) {
                $meal = $indexed->get($id);
                if ($meal === null) {
                    // An insert, not an upsert: an id another day holds fails on the primary key
                    // rather than moving that day's meal here.
                    (new DayTargetMeal(['id' => $id, 'user_id' => $target->user_id, 'day_target_id' => $target->id, 'position' => $position]))->save();
                } elseif ($meal->position !== $position) {
                    $meal->update(['position' => $position]);
                }
            }
        });
    }

    private function refuseMealsWithoutIds(): void
    {
        if (! $this->exists) {
            return;
        }
        foreach ($this->meals as $index => $meal) {
            if (($meal['id'] ?? null) === null) {
                throw ValidationException::withMessages(["meals.{$index}.id" => 'Send the id of every meal the day keeps; a new meal needs a new id.']);
            }
        }
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
