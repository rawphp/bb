<?php

namespace App\Capabilities\Today;

use App\Actions\Today\MoveWorkout;
use App\Capabilities\HostCapability;
use App\Capabilities\Mesocycles\MesocycleReads;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\OwnsInput;
use App\Capabilities\Support\ReadKey;
use App\Capabilities\Support\WriteResult;
use App\Capabilities\Today\Data\WorkoutMoveInput;
use App\Models\Mesocycle;
use App\Models\User;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class WorkoutMoveCapability implements HostCapability, OwnsInput
{
    public function __construct(private readonly MoveWorkout $move) {}

    public function definition(): CapabilityDefinitionBuilder
    {
        // SCH-056, UI-010: a move re-targets both days and shows a toast with Undo.
        return HostDefinition::write('workout_move', affects: ['today_show', 'week_show', 'train_show', 'mesocycle_show', 'workout_show', ...TodayReads::SHEETS], reversible: true)
            ->description('Move a planned mesocycle session to another day of its plan week, from today on, at the same clock time. Use it when the user wants to train on a different day ("move tomorrow\'s workout to Saturday"). Both days\' unchecked meals are re-targeted and the old day becomes a rest day; a day that already holds a session, a past day or another plan week is refused.')
            ->cli('workout', 'move')
            ->input(WorkoutMoveInput::class)
            ->run(function (WorkoutMoveInput $input, CapabilityContext $context): WriteResult {
                /** @var User $user */
                $user = $context->user();
                $moved = ($this->move)($user, $input);

                return new WriteResult(
                    invalidates: [...MesocycleReads::keys($input->mesocycle_id), self::workoutKey($input), ...TodayReads::forDays($moved['dates'], $moved['week'])],
                    toast: $moved['toast'],
                );
            });
    }

    /** The session's workout screen, which shows when it is planned. */
    private static function workoutKey(WorkoutMoveInput $input): string
    {
        return ReadKey::for('workout_show', ['mesocycle_id' => $input->mesocycle_id, 'week' => $input->week, 'day' => $input->day]);
    }

    public function owned(User $user): array
    {
        return ['mesocycle_id' => Mesocycle::savedBy($user)];
    }
}
