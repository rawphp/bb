<?php

namespace App\Capabilities\Exercises;

use App\Actions\Exercises\SaveCustomExercise;
use App\Capabilities\Exercises\Data\CustomExerciseSaveInput;
use App\Capabilities\HostCapability;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\OwnsInput;
use App\Capabilities\Support\ReadKey;
use App\Capabilities\Support\WriteResult;
use App\Models\Exercise;
use App\Models\User;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

/** The one create with no client-made ULID (the CAP-019 exception): exercise ids are integers, so the server assigns it. */
final class CustomExerciseSaveCapability implements HostCapability, OwnsInput
{
    public function __construct(private readonly SaveCustomExercise $save) {}

    public function definition(): CapabilityDefinitionBuilder
    {
        return HostDefinition::write('custom_exercise_save', affects: ['exercises_show', 'exercise_show', 'exercise_history_show', 'train_show', 'mesocycle_show', 'workout_show', 'today_show', 'progress_show'])
            ->description('Create a custom exercise, or edit one of the user\'s own, from its name, muscle group, equipment and load type. Use it when an exercise is not in the library: send no exercise_id to create one, or the id of one of the user\'s own custom exercises to change it. A library exercise cannot be edited.')
            ->cli('exercise', 'save')
            ->input(CustomExerciseSaveInput::class)
            ->run(function (CustomExerciseSaveInput $input, CapabilityContext $context): WriteResult {
                /** @var User $user */
                $user = $context->user();

                $existing = $input->exercise_id === null
                    ? null
                    : Exercise::query()->where('user_id', $user->id)->findOrFail(ExerciseReads::id($input->exercise_id));

                $exercise = ($this->save)($user, $existing, $input->name, $input->muscle_group, $input->equipment, $input->load_type);

                return new WriteResult(invalidates: [
                    ReadKey::for('exercises_show'),
                    ...ExerciseReads::mesocycles($user),
                    ...($existing === null ? [] : [
                        ExerciseReads::show($exercise->id),
                        ExerciseReads::history($exercise->id),
                        ...ExerciseReads::workouts($user, $exercise->id),
                        ...ExerciseReads::names($user),
                    ]),
                ]);
            });
    }

    public function owned(User $user): array
    {
        return ['exercise_id' => Exercise::query()->where('user_id', $user->id)];
    }
}
