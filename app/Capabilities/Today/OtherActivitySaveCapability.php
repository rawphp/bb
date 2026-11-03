<?php

namespace App\Capabilities\Today;

use App\Actions\Today\SaveOtherActivity;
use App\Capabilities\HostCapability;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\UsesClientUlid;
use App\Capabilities\Support\WriteResult;
use App\Capabilities\Today\Data\OtherActivitySaveInput;
use App\Models\OtherActivity;
use App\Models\User;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class OtherActivitySaveCapability implements HostCapability
{
    use UsesClientUlid;

    public function __construct(private readonly SaveOtherActivity $save) {}

    public function definition(): CapabilityDefinitionBuilder
    {
        // SCH-056, UI-010: a re-target shows a toast with Undo.
        return HostDefinition::write('other_activity_save', affects: ['today_show', 'week_show', ...TodayReads::SHEETS], reversible: true)
            ->description('Log sport or cardio outside the training plan as the day\'s Other activity, with a name, start time, length and the intensity the user picked (light, moderate or hard), from today on. Its energy is added to the day as carbs and the day type becomes the higher of it and the workout\'s; no meal moves. A day holds one: saving the same id again edits it.')
            ->cli('other-activity', 'save')
            ->input(OtherActivitySaveInput::class)
            ->run(function (OtherActivitySaveInput $input, CapabilityContext $context): WriteResult {
                /** @var User $user */
                $user = $context->user();
                $saved = ($this->save)($user, $this->ownedOrNew(OtherActivity::class, $input->id, $user), $input);

                return new WriteResult(invalidates: TodayReads::forDays($saved['dates'], $saved['week']), toast: $saved['toast']);
            });
    }
}
