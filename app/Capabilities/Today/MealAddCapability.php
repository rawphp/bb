<?php

namespace App\Capabilities\Today;

use App\Actions\Today\AddMeal;
use App\Capabilities\HostCapability;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\UsesClientUlid;
use App\Capabilities\Support\WriteResult;
use App\Capabilities\Today\Data\MealAddInput;
use App\Models\DayTargetMeal;
use App\Models\User;
use DomainException;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class MealAddCapability implements HostCapability
{
    use UsesClientUlid;

    public const TAKEN = 'That meal is already on your plan.';

    public function __construct(private readonly AddMeal $add) {}

    public function definition(): CapabilityDefinitionBuilder
    {
        // UI-010: a redistribution shows a toast with Undo.
        return HostDefinition::write('meal_add', affects: ['today_show', 'week_show', ...TodayReads::SHEETS], reversible: true)
            ->description('Add an extra meal to a day, from today on, at a chosen time. Use it when the user wants another meal or snack in the plan. The day\'s total stays the same: the meals not checked in, locked or hand-edited share it with the new one, and no meal moves.')
            ->cli('meal', 'add')
            ->input(MealAddInput::class)
            ->run(function (MealAddInput $input, CapabilityContext $context): WriteResult {
                /** @var User $user */
                $user = $context->user();
                // CAP-019: another user's meal id is not found; the user's own is already used.
                if ($this->ownedOrNew(DayTargetMeal::class, strtolower($input->id), $user) !== null) {
                    throw new DomainException(self::TAKEN);
                }
                $added = ($this->add)($user, $input);

                return new WriteResult(invalidates: TodayReads::forDays($added['dates'], $added['week']), toast: $added['toast']);
            });
    }
}
