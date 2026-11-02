<?php

namespace App\Capabilities\Mesocycles;

use App\Actions\Mesocycles\AddMesocycleDay;
use App\Capabilities\HostCapability;
use App\Capabilities\Mesocycles\Data\MesocycleDayAddInput;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\OwnsInput;
use App\Capabilities\Support\WriteResult;
use App\Models\Mesocycle;
use App\Models\User;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class MesocycleDayAddCapability implements HostCapability, OwnsInput
{
    public function __construct(private readonly AddMesocycleDay $addDay) {}

    public function definition(): CapabilityDefinitionBuilder
    {
        return HostDefinition::write('mesocycle_day_add', affects: ['train_show', 'mesocycle_show'])
            ->description('Add an empty training day after the last day of a running mesocycle, in every week. Use it when the user wants to train one more day; the day is named after the next free training weekday, or numbered. Sending the same day_id again changes nothing.')
            ->cli('mesocycle', 'day-add')
            ->input(MesocycleDayAddInput::class)
            ->run(function (MesocycleDayAddInput $input, CapabilityContext $context): WriteResult {
                /** @var User $user */
                $user = $context->user();
                ($this->addDay)($user, $input->mesocycle_id, $input->day_id);

                return new WriteResult(invalidates: MesocycleReads::keys($input->mesocycle_id), toast: 'Day added');
            });
    }

    public function owned(User $user): array
    {
        return ['mesocycle_id' => Mesocycle::query()->where('user_id', $user->id)->where('is_draft', false)];
    }
}
