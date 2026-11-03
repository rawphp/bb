<?php

namespace App\Actions\Exercises;

use App\Enums\SfrRating;
use App\Models\Exercise;
use App\Models\User;

/**
 * EX-004, EX-005: create or edit the user's custom exercise. A new one takes rp-clone's server defaults: SFR medium,
 * 10 to 20 reps, not compound, no video. An edit changes the name, muscle group, equipment and load type of the row.
 */
final class SaveCustomExercise
{
    /** @param  Exercise|null  $exercise  the user's own custom exercise to edit; null creates one */
    public function __invoke(User $user, ?Exercise $exercise, string $name, string $muscleGroup, string $equipment, string $loadType): Exercise
    {
        if ($exercise === null) {
            $exercise = new Exercise([
                'sfr_rating' => SfrRating::Medium, 'rep_range_min' => 10, 'rep_range_max' => 20, 'is_compound' => false, 'video_url' => null,
            ]);
            $exercise->user_id = $user->id;
        }

        $exercise->fill(['name' => trim($name), 'muscle_group' => $muscleGroup, 'equipment' => $equipment, 'load_type' => $loadType])->save();

        return $exercise;
    }
}
