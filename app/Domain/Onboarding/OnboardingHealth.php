<?php

namespace App\Domain\Onboarding;

use App\Domain\Defaults\TBL001BodyFatBuckets;
use App\Domain\Defaults\TBL001BodyFatBuckets\Row;
use App\Domain\Model\PhaseType;
use App\Domain\Model\Sex;
use App\Domain\Safety\Eligibility;
use App\Domain\Safety\EligibilityMode;
use App\Domain\Safety\Screening;
use App\Domain\Safety\ScreeningItem;
use DateTimeImmutable;

/**
 * Steps 1 to 5 as setup reads them (rp-clone OnboardingModel and OnboardingModel+Health): the goal options and their
 * notices (ONB-003, ONB-005, ONB-021), about you (ONB-004), the screening list and what it allows (SAFE-001,
 * SAFE-003), the weight-loss drug question (ONB-006) and the body-fat buckets (ONB-007).
 */
final readonly class OnboardingHealth
{
    public const GOAL_HELP = 'Want to lose fat and build muscle at the same time? Pick Fat loss. There is no separate recomp goal.';

    public const RECENT_DIET_QUESTION = 'Have you finished a fat-loss diet recently?';

    public const RECENT_DIET_DETAIL = 'In the past 8 weeks';

    /** ONB-005: why the Fat loss option is off. */
    public const FAT_LOSS_BLOCKED_REASON = "Fat loss isn't available while you're trying to conceive or in fertility treatment.";

    public const FAT_LOSS_BLOCKED_NOTICE = self::FAT_LOSS_BLOCKED_REASON.' Pick Maintenance or Muscle gain.';

    public const TRAINING_ONLY_INTRO = 'Nutrition targets are turned off. You can still use every training feature, '
        .'log weigh-ins and see your trend weight.';

    public const MAINTENANCE_FIRST_ADVICE = 'You finished a fat-loss diet recently, so we recommend starting with Maintenance first. '
        .'A few weeks at maintenance after a diet lets your hunger, energy and training recover, '
        .'so your next fat-loss phase works better. You can still choose Fat loss.';

    public const MAINTENANCE_FIRST_ACTION = 'Start with Maintenance';

    public const SCREENING_PROMPT = 'Tick anything that applies to you.';

    /** SAFE-002 */
    public const MEDICAL_CLEARANCE_NOTE = 'Get medical clearance from your doctor before starting any diet.';

    /** SAFE-014 */
    public const MEDICAL_DIETS_NOTE = 'This app does not support medical diets. If you have a metabolic condition, consult a physician first.';

    public const MEASURED_DETAIL = 'From your answers on step 2';

    public const GLP1_QUESTION = 'Do you take a GLP-1 or similar weight-loss drug?';

    public const GLP1_DETAIL = 'For example semaglutide, tirzepatide or retatrutide.';

    public const BODY_FAT_HELP = "Pick the photo that looks most like you. Accuracy isn't critical. "
        ."Don't use body-fat readings from a smart scale.";

    /** The goals step 1 offers, in order. */
    public const OFFERED_GOALS = [PhaseType::FatLoss, PhaseType::MuscleGain, PhaseType::Maintenance];

    /** The date of birth the picker starts from, in years before today. */
    private const DEFAULT_AGE_YEARS = 30;

    private DateTimeImmutable $today;

    public function __construct(private Draft $draft, DateTimeImmutable $today)
    {
        $this->today = OnboardingDates::day($today);
    }

    // Step 1 (ONB-003, ONB-005, ONB-021)

    /** @return list<GoalOption> */
    public function goalOptions(): array
    {
        return array_map(fn (PhaseType $goal) => new GoalOption(
            $goal, $goal->title(),
            $this->isGoalAllowed($goal) ? null : self::FAT_LOSS_BLOCKED_REASON,
            $this->isGoalAllowed($goal),
        ), self::OFFERED_GOALS);
    }

    /** @return list<PhaseType> */
    public function allowedGoals(): array
    {
        return array_values(array_filter(self::OFFERED_GOALS, fn (PhaseType $goal) => $this->isGoalAllowed($goal)));
    }

    /** SAFE-001: "trying to conceive" blocks Fat loss only. Training-only still takes a goal (ONB-005). */
    public function isGoalAllowed(PhaseType $goal): bool
    {
        if (! in_array($goal, self::OFFERED_GOALS, true)) {
            return false;
        }

        return $this->eligibility()->mode !== EligibilityMode::FatLossBlocked || ! $goal->isFatLoss();
    }

    /**
     * ONB-005: on step 1 while Fat loss is blocked and no other goal is picked, so it is still there after the
     * screening sends the user back, or after quitting and reopening.
     */
    public function notice(): ?string
    {
        $goal = $this->draft->goal;

        return $this->eligibility()->mode === EligibilityMode::FatLossBlocked && ($goal === null || $goal === PhaseType::FatLoss)
            ? self::FAT_LOSS_BLOCKED_NOTICE
            : null;
    }

    /** ONB-021: Fat loss picked after a recent diet. The user can still go on. */
    public function maintenanceFirstAdvice(): ?MaintenanceFirstAdvice
    {
        return $this->draft->goal === PhaseType::FatLoss && $this->draft->finishedDietRecently === true
            ? new MaintenanceFirstAdvice(self::MAINTENANCE_FIRST_ADVICE, self::MAINTENANCE_FIRST_ACTION)
            : null;
    }

    // Step 2 (ONB-004)

    /** Every field answered, with sizes above zero and a date of birth before today. */
    public function hasAboutYou(): bool
    {
        $draft = $this->draft;

        return trim($draft->name) !== ''
            && $draft->sex !== null
            && $draft->birthDate !== null
            && ($draft->heightCm ?? 0) > 0
            && ($draft->weightKg ?? 0) > 0
            && OnboardingDates::day($draft->birthDate) < $this->today;
    }

    /** The date of birth the picker starts from: 30 years ago (the last day of the month when that day is missing). */
    public function defaultBirthDate(): DateTimeImmutable
    {
        $year = (int) $this->today->format('Y') - self::DEFAULT_AGE_YEARS;
        $month = (int) $this->today->format('n');
        $lastDay = (int) $this->today->setDate($year, $month, 1)->format('t');

        return $this->today->setDate($year, $month, min((int) $this->today->format('j'), $lastDay));
    }

    // Step 3 (SAFE-001, SAFE-003)

    /**
     * Age, height, weight and BMI worked out from step 2; none until step 2 is answered.
     *
     * @return list<ScreeningItem>
     */
    public function measuredItems(): array
    {
        $draft = $this->draft;
        if ($draft->birthDate === null || ($draft->heightCm ?? 0) <= 0 || ($draft->weightKg ?? 0) <= 0) {
            return [];
        }

        return Screening::measuredItemsFrom($draft->birthDate, (float) $draft->heightCm, (float) $draft->weightKg, $this->today);
    }

    /** SAFE-001: what the screening allows, from the ticked and the measured items. */
    public function eligibility(): Eligibility
    {
        return Screening::eligibilityFor([...$this->draft->screening, ...$this->measuredItems()]);
    }

    public function isTrainingOnly(): bool
    {
        return $this->eligibility()->mode === EligibilityMode::TrainingOnly;
    }

    /** SAFE-003: once screening is passed training-only, says nutrition targets are off and why. */
    public function trainingOnlyNotice(): ?string
    {
        $eligibility = $this->eligibility();
        if ($this->draft->step <= 3 || $eligibility->mode !== EligibilityMode::TrainingOnly) {
            return null;
        }

        return implode("\n", [self::TRAINING_ONLY_INTRO, ...array_map(
            fn (ScreeningItem $item) => '• '.$item->reason(), $eligibility->reasons)]);
    }

    /**
     * SAFE-001's list, in the spec's order.
     *
     * @return list<ScreeningRow>
     */
    public function screeningRows(): array
    {
        $measured = $this->measuredItems();

        return array_map(function (ScreeningItem $item) use ($measured) {
            $isMeasured = in_array($item, $measured, true);

            return new ScreeningRow(
                item: $item,
                label: self::label($item),
                detail: $isMeasured ? self::MEASURED_DETAIL : ($item === ScreeningItem::TryingToConceive ? 'Only Fat loss is unavailable.' : null),
                isOn: $isMeasured || in_array($item, $this->draft->screening, true),
                isLocked: in_array($item, Screening::MEASURED_KINDS, true),
            );
        }, ScreeningItem::cases());
    }

    // Step 5 (ONB-007)

    /**
     * The four buckets for the chosen sex (TBL-001), leanest first; female until a sex is picked.
     *
     * @return list<Row>
     */
    public function bodyFatRows(): array
    {
        return TBL001BodyFatBuckets::rows($this->draft->sex ?? Sex::Female);
    }

    private static function label(ScreeningItem $item): string
    {
        return match ($item) {
            ScreeningItem::AgeOutOfRange => 'Under 18 or over 65',
            ScreeningItem::HeightOutOfRange => 'Height under 54 in (137 cm) or over 84 in (213 cm)',
            ScreeningItem::WeightOverLimit => 'Weight over 350 lb (159 kg)',
            ScreeningItem::BmiUnderLimit => 'BMI under 17',
            ScreeningItem::PregnantOrBreastfeeding => 'Pregnant or breastfeeding',
            ScreeningItem::TryingToConceive => 'Trying to conceive or in fertility treatment',
            ScreeningItem::TransgenderOnHormoneTherapy => 'Transgender and on hormone therapy',
            ScreeningItem::HeartThyroidOrPCOS => 'Heart disease, thyroid cancer, PCOS, or hyper- or hypothyroidism',
            ScreeningItem::WeightLossSurgery => 'Weight-loss surgery in the past 5 years',
            ScreeningItem::GallbladderDisease => 'Gallbladder disease',
            ScreeningItem::AutoimmuneDisease => 'Autoimmune disease',
            ScreeningItem::Diabetes => 'Type 1 or type 2 diabetes',
            ScreeningItem::EatingDisorderHistory => 'A history of eating disorders',
        };
    }
}
