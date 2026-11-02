<?php

namespace Tests\Support\Fixtures\Undo;

use App\Capabilities\HostCapability;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\ReadKey;
use App\Capabilities\Support\UndoLog;
use App\Capabilities\Support\WriteResult;
use App\Enums\MeasurementSource;
use App\Models\Step;
use App\Models\User;
use App\Models\WeighIn;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

/**
 * A reversible write for undo tests. It sets the step count for 2026-11-02 and,
 * as automatic changes, adds the same count for 2026-11-03 and removes the
 * user's weigh-ins: an update, a create and a delete in one write.
 */
final class StepCountSetCapability implements HostCapability
{
    public function __construct(private readonly UndoLog $undo) {}

    public function definition(): CapabilityDefinitionBuilder
    {
        return HostDefinition::write('step_count_set', affects: ['steps_show'], reversible: true)
            ->description('Set the test step count. Used by undo tests only.')
            ->cli('step-count', 'set')
            ->input(StepCountSetInput::class)
            ->run(function (StepCountSetInput $input, CapabilityContext $context): WriteResult {
                /** @var User $user */
                $user = $context->user();

                return $this->undo->record($user, function () use ($input, $user): WriteResult {
                    Step::query()->whereBelongsTo($user)->whereDate('date', '2026-11-02')->firstOrFail()
                        ->update(['count' => $input->count]);

                    $next = Step::query()->whereBelongsTo($user)->whereDate('date', '2026-11-03')->firstOrNew();
                    $next->fill(['date' => '2026-11-03', 'count' => $input->count, 'source' => MeasurementSource::Manual]);
                    $next->user()->associate($user)->save();

                    WeighIn::query()->whereBelongsTo($user)->get()->each->delete();

                    return new WriteResult(invalidates: [ReadKey::for('steps_show')], toast: "Steps set to {$input->count}");
                });
            });
    }
}
