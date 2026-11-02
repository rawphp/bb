<?php

namespace App\Capabilities\Workout;

use App\Actions\Workout\SetWorkoutExerciseNote;
use App\Capabilities\HostCapability;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\OwnsInput;
use App\Capabilities\Support\WriteResult;
use App\Capabilities\Workout\Data\WorkoutExerciseNoteSetInput;
use App\Models\User;
use App\Models\WorkoutExercise;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class WorkoutExerciseNoteSetCapability implements HostCapability, OwnsInput
{
    public function __construct(private readonly SetWorkoutExerciseNote $note) {}

    public function definition(): CapabilityDefinitionBuilder
    {
        return HostDefinition::write('workout_exercise_note_set', affects: ['workout_show'])
            ->description('Set or clear this session\'s note on one exercise of a session under way, such as "superset with face pulls". A null note clears it. The note that shows every time the exercise appears is exercise_note_set.')
            ->cli('workout', 'note-set')
            ->input(WorkoutExerciseNoteSetInput::class)
            ->run(function (WorkoutExerciseNoteSetInput $input, CapabilityContext $context): WriteResult {
                /** @var User $user */
                $user = $context->user();
                $session = ($this->note)($user, $input->workout_exercise_id, $input->note);

                return new WriteResult(invalidates: [WorkoutReads::workout($session)]);
            });
    }

    public function owned(User $user): array
    {
        return ['workout_exercise_id' => WorkoutExercise::query()->where('user_id', $user->id)];
    }
}
