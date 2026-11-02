<?php

namespace App\Capabilities\Today;

use App\Actions\Today\DeleteOtherActivity;
use App\Capabilities\HostCapability;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\OwnsInput;
use App\Capabilities\Support\WriteResult;
use App\Capabilities\Today\Data\OtherActivityDeleteInput;
use App\Models\OtherActivity;
use App\Models\User;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class OtherActivityDeleteCapability implements HostCapability, OwnsInput
{
    public function __construct(private readonly DeleteOtherActivity $delete) {}

    public function definition(): CapabilityDefinitionBuilder
    {
        // AGT-010: a delete needs the user's say-so before the coach makes it. SCH-056, UI-010: its re-target has Undo.
        return HostDefinition::write('other_activity_delete', affects: ['today_show', 'week_show', ...TodayReads::SHEETS], reversible: true, destructive: true)
            ->description('Delete a day\'s Other activity, from today on, and take its energy back out of the day\'s targets. Use it when the user did not do the activity or logged it by mistake. The day\'s unchecked meals are re-targeted; no meal moves.')
            ->cli('other-activity', 'delete')
            ->input(OtherActivityDeleteInput::class)
            ->run(function (OtherActivityDeleteInput $input, CapabilityContext $context): WriteResult {
                /** @var User $user */
                $user = $context->user();
                $deleted = ($this->delete)($user, $input->id);

                return new WriteResult(invalidates: TodayReads::forDays($deleted['dates'], $deleted['week']), toast: $deleted['toast']);
            });
    }

    public function owned(User $user): array
    {
        return ['id' => OtherActivity::query()->where('user_id', $user->id)];
    }
}
