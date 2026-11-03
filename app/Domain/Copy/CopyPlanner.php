<?php

namespace App\Domain\Copy;

use App\Domain\MealFit\MealFit;
use App\Domain\MealFit\MealFood;
use App\Domain\Model\MealTarget;
use App\Domain\Recipe\RecipeServing;
use App\Domain\WeekBuilder\MealNames;

/** MEAL-060, SCH-034, SCH-035: what a meal's list becomes when it is copied into other meals. */
final readonly class CopyPlanner
{
    public function __construct(private MealFit $fit) {}

    /**
     * The source's entries for a destination meal with `$target`, in the same order. With update target macros the
     * unlocked foods and recipe servings are fitted to the target while locked ones keep their amounts (MEAL-005,
     * RCP-002), through the one meal fit, and one the fit leaves below 1 g is planned at 1 g, a tenth of a serving.
     * With it off, the source's amounts are copied as they are. Without copy locked state the copies arrive unlocked,
     * after the fit.
     *
     * @param  list<MealFood>  $source
     * @return list<MealFood>
     */
    public function entries(array $source, MealTarget $target, CopyOptions $options): array
    {
        $fitted = $options->updateTargetMacros && $source !== []
            ? array_map(
                fn (MealFood $f) => ! $f->isLocked && $f->grams < RecipeServing::MIN_GRAMS ? $f->withGrams(RecipeServing::MIN_GRAMS) : $f,
                $this->fit->fit($source, $target)->foods,
            )
            : $source;

        return array_map(fn (MealFood $entry) => $options->copyLockedState ? $entry : $entry->withLocked(false), $fitted);
    }

    /**
     * Each pair's destination takes its source's entries, replacing what it had. Skipped: a destination that is its own
     * source, is checked in or was written by an earlier pair, and a source with nothing to copy. With update meal times
     * the destination also takes the source's time, unless it is an intra-workout shake, which keeps its
     * workout-relative time.
     *
     * @param  list<array{source: CopyMeal, destination: CopyMeal}>  $pairs
     * @return list<CopyWrite>
     */
    public function writes(array $pairs, CopyOptions $options): array
    {
        $writes = [];
        foreach ($pairs as ['source' => $source, 'destination' => $destination]) {
            if ($source->id === $destination->id || $destination->isCheckedIn || $source->entries === []
                || isset($writes[$destination->id])) {
                continue;
            }
            $moves = $options->updateMealTimes && $source->time !== null && $destination->time !== $source->time
                && $destination->name !== MealNames::INTRA_WORKOUT_SHAKE;
            $writes[$destination->id] = new CopyWrite(
                sourceId: $source->id,
                destinationId: $destination->id,
                entries: $this->entries($source->entries, $destination->target, $options),
                time: $moves ? $source->time : null,
            );
        }

        return array_values($writes);
    }
}
