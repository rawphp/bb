<?php

namespace App\Capabilities\Reads;

use App\Capabilities\Exercises\Data\ExerciseShowInput;
use App\Capabilities\Exercises\Data\ExerciseShowOutput;
use App\Capabilities\HostCapability;
use App\Capabilities\Reads\Exercises\ExercisePayload;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\OwnsInput;
use App\Models\Exercise;
use App\Models\ExerciseLoadStep;
use App\Models\ExerciseNote;
use App\Models\Mesocycle;
use App\Models\Profile;
use App\Models\User;
use App\Support\Mesocycles\TrainSnapshot;
use App\Support\Progress\ExerciseHistorySnapshot;
use App\Support\UserDay;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class ExerciseShowCapability implements HostCapability, OwnsInput
{
    public function definition(): CapabilityDefinitionBuilder
    {
        return HostDefinition::read('exercise_show')
            ->description('Show one exercise: its muscle group, equipment, load type, technique video, pinned note and load step in the user\'s unit, its rep range when it is in the current mesocycle, and every logged set of it, newest day first. Use it to look at an exercise before changing its note or load step. It changes nothing.')
            ->cli('exercise', 'show')
            ->input(ExerciseShowInput::class)
            ->output(ExerciseShowOutput::class)
            ->run(function (ExerciseShowInput $input, CapabilityContext $context): ExerciseShowOutput {
                /** @var User $user */
                $user = $context->user();
                $history = ExerciseHistorySnapshot::every($user, $input->exercise_id)
                    ?? throw (new ModelNotFoundException)->setModel(Exercise::class, [$input->exercise_id]);
                $exercise = $history->exercise;

                return ExercisePayload::build(
                    $exercise,
                    $user->id,
                    $history->unit,
                    (bool) Profile::query()->where('user_id', $user->id)->value('micro_plates'),
                    $this->inCurrentMesocycle($user, $exercise),
                    ExerciseNote::query()->where('user_id', $user->id)->where('exercise_id', $exercise->id)->value('note'),
                    ExerciseLoadStep::query()->where('user_id', $user->id)->where('exercise_id', $exercise->id)->value('step_kg'),
                    $history->sets,
                );
            });
    }

    public function owned(User $user): array
    {
        return ['exercise_id' => Exercise::query()->visibleTo($user)];
    }

    /** EX-007: a slot of the mesocycle that is current on the user's local today (SET-012) names the exercise. */
    private function inCurrentMesocycle(User $user, Exercise $exercise): bool
    {
        $current = TrainSnapshot::calendarOnly(
            UserDay::today($user),
            Mesocycle::query()->where('user_id', $user->id)->get(),
        )->current();

        return $current !== null
            && collect($current->dayRows())->flatMap(fn (array $day) => $day['slots'])->contains('exercise_id', $exercise->id);
    }
}
