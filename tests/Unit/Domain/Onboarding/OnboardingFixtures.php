<?php

namespace Tests\Unit\Domain\Onboarding;

use App\Domain\Model\BodyFatBucket;
use App\Domain\Model\ExperienceLevel;
use App\Domain\Model\MuscleGroup;
use App\Domain\Model\PhaseType;
use App\Domain\Model\Sex;
use App\Domain\Onboarding\Draft;
use App\Domain\Onboarding\OnboardingStart;
use App\Domain\Onboarding\OnboardingTemplate;
use App\Domain\Onboarding\OnboardingTemplateDay;
use App\Domain\Onboarding\OnboardingTemplateSlot;
use App\Domain\Onboarding\TrainingDayChoice;
use App\Domain\Onboarding\TrainingPlanKind;
use App\Domain\Onboarding\WeighInChoice;
use App\Domain\Safety\ScreeningItem;
use App\Enums\PhaseFit;
use App\Enums\SexFit;
use App\Enums\Split;
use DateTimeImmutable;
use DateTimeZone;

/**
 * rp-clone's OnboardingFixture as drafts: today is Saturday 2026-10-10 in UTC, so tomorrow is Sunday 2026-10-11. The
 * person is Sam, a woman born 9 April 1990, 170 cm and 80 kg. Each `at…` draft has every step before it answered as
 * rp-clone's fixture walks it, and picking a goal fills in step 6's defaults as rp-clone's selectGoal does.
 */
final class OnboardingFixtures
{
    /** Monday, Wednesday and Friday (ISO weekdays). */
    public const THREE_DAYS = [1, 3, 5];

    public static function date(int $year, int $month, int $day): DateTimeImmutable
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->setDate($year, $month, $day)->setTime(0, 0);
    }

    public static function today(): DateTimeImmutable
    {
        return self::date(2026, 10, 10)->setTime(9, 0);
    }

    public static function tomorrow(): DateTimeImmutable
    {
        return self::date(2026, 10, 11);
    }

    public static function adultBirthDate(): DateTimeImmutable
    {
        return self::date(1990, 4, 9);
    }

    public static function minorBirthDate(): DateTimeImmutable
    {
        return self::date(2010, 4, 9);
    }

    /** Step 1 answered: the goal with its step 6 defaults, and no recent diet. */
    public static function atStep2(PhaseType $goal = PhaseType::FatLoss, bool $recentDiet = false): Draft
    {
        [$rate, $weeks] = match ($goal) {
            PhaseType::MuscleGain => [0.375, 12],
            PhaseType::Maintenance => [0.0, 4],
            default => [-0.75, 12],
        };

        return new Draft(
            step: 2, goal: $goal, finishedDietRecently: $recentDiet,
            ratePercentPerWeek: $rate, weeks: $weeks, startDate: self::tomorrow(),
        );
    }

    public static function atStep3(PhaseType $goal = PhaseType::FatLoss, ?DateTimeImmutable $birthDate = null): Draft
    {
        return self::atStep2($goal)->with(
            step: 3, name: 'Sam', sex: Sex::Female, birthDate: $birthDate ?? self::adultBirthDate(),
            heightCm: 170.0, weightKg: 80.0,
        );
    }

    public static function atStep6(PhaseType $goal = PhaseType::FatLoss): Draft
    {
        return self::atStep3($goal)->with(step: 6, usesGLP1: false, bodyFatBucket: BodyFatBucket::Low);
    }

    public static function atStep7(PhaseType $goal = PhaseType::FatLoss): Draft
    {
        return self::atStep6($goal)->with(step: 7);
    }

    /** A training-only setup (a diabetes answer) on step 7: steps 4 to 6 were skipped. */
    public static function atStep7TrainingOnly(PhaseType $goal = PhaseType::MuscleGain): Draft
    {
        return self::atStep3($goal)->with(step: 7, screening: [ScreeningItem::Diabetes]);
    }

    public static function atStep8(PhaseType $goal = PhaseType::FatLoss): Draft
    {
        return self::atStep7($goal)->with(step: 8, experience: ExperienceLevel::Intermediate);
    }

    /** @param  list<int>  $days */
    public static function atStep9(PhaseType $goal = PhaseType::FatLoss, array $days = self::THREE_DAYS): Draft
    {
        return self::atStep8($goal)->with(step: 9, trainingDays: self::trainingDays($days));
    }

    /** "I'll build it later" as the training plan. */
    public static function atStep10(PhaseType $goal = PhaseType::FatLoss): Draft
    {
        return self::atStep9($goal)->with(step: 10, trainingPlan: TrainingPlanKind::Later);
    }

    public static function atStep11(PhaseType $goal = PhaseType::FatLoss): Draft
    {
        return self::atStep10($goal)->with(step: 11);
    }

    public static function atStep12(PhaseType $goal = PhaseType::FatLoss): Draft
    {
        return self::atStep11($goal)->with(step: 12);
    }

    /** Weighing in on Monday, Wednesday and Friday, so the review day is Saturday. */
    public static function atStep13(PhaseType $goal = PhaseType::FatLoss): Draft
    {
        return self::atStep12($goal)->with(step: 13, weighIns: self::weighIns(self::THREE_DAYS));
    }

    /** A nutrition setup on the review, on the full-body template (id 2). */
    public static function atReview(PhaseType $goal = PhaseType::FatLoss): Draft
    {
        return self::atStep13($goal)->with(step: 14, trainingPlan: TrainingPlanKind::Template, templateId: 2);
    }

    /**
     * A training-only setup on the review: Muscle gain and diabetes, a beginner training Tuesday and Thursday,
     * weighing in on Monday and Friday, with the plan left to build later.
     */
    public static function atTrainingOnlyReview(): Draft
    {
        return self::atStep7TrainingOnly(PhaseType::MuscleGain)->with(
            step: 14, experience: ExperienceLevel::Beginner, trainingDays: self::trainingDays([2, 4]),
            trainingPlan: TrainingPlanKind::Later, weighIns: self::weighIns([1, 5]),
        );
    }

    /**
     * @param  list<int>  $weekdays
     * @return list<TrainingDayChoice>
     */
    public static function trainingDays(array $weekdays): array
    {
        return array_map(fn (int $weekday) => new TrainingDayChoice($weekday, 18 * 60, 60), $weekdays);
    }

    /**
     * @param  list<int>  $weekdays
     * @return list<WeighInChoice>
     */
    public static function weighIns(array $weekdays): array
    {
        return array_map(fn (int $weekday) => new WeighInChoice($weekday, 7 * 60), $weekdays);
    }

    /** @param  list<PhaseFit>  $phaseFit */
    public static function template(
        int $id, string $name, Split $split = Split::FullBody, int $days = 3, SexFit $sex = SexFit::Any,
        array $phaseFit = [PhaseFit::Cut, PhaseFit::Maintain],
    ): OnboardingTemplate {
        return new OnboardingTemplate(
            id: $id, name: $name, split: $split, daysPerWeek: $days, sex: $sex, phaseFit: $phaseFit,
            priorities: ['chest' => 'grow', 'sideDelts' => 'emphasize'],
            days: array_map(fn (int $day) => new OnboardingTemplateDay("Day {$day}", [
                new OnboardingTemplateSlot("Bench press {$day}", MuscleGroup::Chest, 3),
                new OnboardingTemplateSlot("Lateral raise {$day}", MuscleGroup::SideDelts, 2),
            ]), range(1, $days)),
        );
    }

    /**
     * The templates the review drafts pick from.
     *
     * @return list<OnboardingTemplate>
     */
    public static function catalogue(): array
    {
        return [
            self::template(2, 'Full body three'),
            self::template(3, 'Delts three', Split::Specialisation, phaseFit: [PhaseFit::Gain, PhaseFit::Cut, PhaseFit::Maintain]),
        ];
    }

    /**
     * The review templates with exercise ids: day d's slots are exercises 100 + d and 200 + d.
     *
     * @return list<OnboardingTemplate>
     */
    public static function startCatalogue(): array
    {
        $template = fn (int $id, string $name, Split $split, array $phaseFit) => new OnboardingTemplate(
            id: $id, name: $name, split: $split, daysPerWeek: 3, sex: SexFit::Any, phaseFit: $phaseFit,
            priorities: ['chest' => 'grow', 'sideDelts' => 'emphasize'],
            days: array_map(fn (int $day) => new OnboardingTemplateDay("Day {$day}", [
                new OnboardingTemplateSlot("Bench press {$day}", MuscleGroup::Chest, 3, 100 + $day),
                new OnboardingTemplateSlot("Lateral raise {$day}", MuscleGroup::SideDelts, 2, 200 + $day),
            ]), [1, 2, 3]),
        );

        return [
            $template(2, 'Full body three', Split::FullBody, [PhaseFit::Cut, PhaseFit::Maintain]),
            $template(3, 'Delts three', Split::Specialisation, [PhaseFit::Gain, PhaseFit::Cut, PhaseFit::Maintain]),
        ];
    }

    /** "Start my plan" on the start catalogue, with numbered ids. */
    public static function start(Draft $draft, ?DateTimeImmutable $today = null): OnboardingStart
    {
        $ids = 0;

        return OnboardingStart::from($draft, $today ?? self::today(), self::startCatalogue(), function () use (&$ids) {
            $ids++;

            return sprintf('00000000-0000-4000-8000-%012d', $ids);
        });
    }
}
