<?php

namespace App\Capabilities\Today;

use App\Actions\Today\SetBusyPeriod;
use App\Capabilities\HostCapability;
use App\Capabilities\Settings\SettingsReads;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\WriteResult;
use App\Capabilities\Today\Data\BusyPeriodSetInput;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class BusyPeriodSetCapability implements HostCapability
{
    public function __construct(private readonly SetBusyPeriod $set) {}

    public function definition(): CapabilityDefinitionBuilder
    {
        return HostDefinition::write('busy_period_set', affects: [...SettingsReads::REBUILDS, ...TodayReads::SHEETS])
            ->description('Add a repeating busy period to the user\'s schedule: a label, a start and end time ("HH:MM", 24-hour) and the ISO weekdays it repeats on (1 = Monday). Saving the same id again edits it. Meals are placed outside busy periods, and the targets are recalculated from tomorrow.')
            ->cli('busy-period', 'set')
            ->input(BusyPeriodSetInput::class)
            ->run(function (BusyPeriodSetInput $input, CapabilityContext $context): WriteResult {
                /** @var User $user */
                $user = $context->user();
                // CAP-019: an id another user's busy period holds is not found.
                $theirs = Profile::query()->where('user_id', '!=', $user->id)
                    ->whereJsonContains('busy_periods', [['id' => strtolower($input->id)]])->exists();
                if ($theirs) {
                    throw (new ModelNotFoundException)->setModel(Profile::class, [$input->id]);
                }

                return new WriteResult(invalidates: SettingsReads::keys(($this->set)($user, $input)));
            });
    }
}
