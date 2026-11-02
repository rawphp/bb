<?php

namespace App\Capabilities\Settings;

use App\Actions\Settings\RecheckEligibility;
use App\Capabilities\HostCapability;
use App\Capabilities\Plan\PlanReads;
use App\Capabilities\Settings\Data\EligibilityRecheckInput;
use App\Capabilities\Settings\Data\EligibilityRecheckOutput;
use App\Capabilities\Settings\Data\ScreeningAnswerInput;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\ReadKey;
use App\Capabilities\Support\WriteResult;
use App\Capabilities\Today\TodayReads;
use App\Domain\Safety\ScreeningItem;
use App\Models\Phase;
use App\Models\User;
use Carbon\CarbonImmutable;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class EligibilityRecheckCapability implements HostCapability
{
    public function __construct(private readonly RecheckEligibility $recheck) {}

    public function definition(): CapabilityDefinitionBuilder
    {
        // A switch to training only ends the phase and removes the targets after today (AGT-010).
        return HostDefinition::write('eligibility_recheck', affects: ['eligibility_recheck_show', ...PlanReads::AFFECTS], destructive: true)
            ->description('Re-run the health screening: answer every screening item, saying whether it applies to the user now; age, height, weight and BMI are measured from their profile, so their answers are ignored. A user who now fails moves to training only at once: the phase switches from this plan week and the nutrition targets after today are removed, which cannot be undone. A training-only user who now passes, or a Fat loss or Mini-cut user now trying to conceive, is sent to onboarding for the nutrition steps or a new goal; otherwise only the answers are saved.')
            ->cli('eligibility', 'recheck')
            ->input(EligibilityRecheckInput::class)
            ->output(EligibilityRecheckOutput::class)
            ->run(fn (EligibilityRecheckInput $input, CapabilityContext $context): EligibilityRecheckOutput => $this->run($input, $context));
    }

    private function run(EligibilityRecheckInput $input, CapabilityContext $context): EligibilityRecheckOutput
    {
        /** @var User $user */
        $user = $context->user();
        $ticked = array_values(array_map(
            fn (ScreeningAnswerInput $answer) => ScreeningItem::from($answer->item),
            array_filter($input->answers, fn (ScreeningAnswerInput $answer) => $answer->applies)));

        $recheck = ($this->recheck)($user, $ticked);

        $invalidates = [ReadKey::for('eligibility_recheck_show')];
        if ($recheck->phaseIds !== []) {
            // Training only changes today's screen too, and every phase's, which then show no rates or calories (SAFE-003).
            $today = CarbonImmutable::now()->format('Y-m-d');
            $phaseIds = Phase::query()->where('user_id', $user->id)->pluck('id')->map(fn ($id) => (string) $id)->all();
            $invalidates = array_values(array_unique([
                ...$invalidates,
                ...PlanReads::keys($user, ...$phaseIds),
                ...TodayReads::keys([$today, ...$recheck->removedDates]),
                ...TodayReads::weekKeys($recheck->weekDates),
            ]));
        }

        return new EligibilityRecheckOutput(
            outcome: $recheck->kind->value,
            message: $recheck->message,
            next: $recheck->next,
            result: new WriteResult(invalidates: $invalidates),
        );
    }
}
