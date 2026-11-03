<?php

namespace App\Capabilities\Exercises;

use App\Actions\Exercises\SetExerciseLoadStep;
use App\Capabilities\Exercises\Data\ExerciseLoadStepSetInput;
use App\Capabilities\HostCapability;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\OwnsInput;
use App\Capabilities\Support\WriteResult;
use App\Domain\Model\WeightUnit;
use App\Models\Exercise;
use App\Models\User;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class ExerciseLoadStepSetCapability implements HostCapability, OwnsInput
{
    public function __construct(private readonly SetExerciseLoadStep $step) {}

    public function definition(): CapabilityDefinitionBuilder
    {
        return HostDefinition::write('exercise_load_step_set', affects: ['exercise_show', 'workout_show'])
            ->description('Set how much weight the app adds between sets of an exercise, in the unit sent, such as 4.5 for a cable stack. A null step goes back to the default for the exercise\'s load type. It changes the weights the workout suggests.')
            ->cli('exercise', 'load-step-set')
            ->input(ExerciseLoadStepSetInput::class)
            ->run(function (ExerciseLoadStepSetInput $input, CapabilityContext $context): WriteResult {
                /** @var User $user */
                $user = $context->user();
                $id = ExerciseReads::id($input->exercise_id);

                ($this->step)($user, Exercise::query()->visibleTo($user)->findOrFail($id), $input->step, WeightUnit::from($input->unit));

                return new WriteResult(invalidates: [ExerciseReads::show($id), ...ExerciseReads::workouts($user, $id)]);
            });
    }

    public function owned(User $user): array
    {
        return ['exercise_id' => Exercise::query()->visibleTo($user)];
    }
}
