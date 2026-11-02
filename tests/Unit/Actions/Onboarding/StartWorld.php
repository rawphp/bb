<?php

namespace Tests\Unit\Actions\Onboarding;

use App\Domain\Model\MuscleGroup;
use App\Enums\PhaseFit;
use App\Enums\SexFit;
use App\Enums\Split;
use App\Models\Exercise;
use App\Models\OnboardingDraft;
use App\Models\Template;
use App\Models\TemplateSlot;
use App\Models\User;

/**
 * A draft on the review, saved: rp-clone's OnboardingFixture as a row. Sam starts a Fat loss plan on Sunday
 * 11 October 2026, trains Monday, Wednesday and Friday at 18:00 and weighs in on the same days at 07:00, so the plan
 * reviews on Saturdays. Training is a three-day full-body template.
 */
final class StartWorld
{
    /** A three-day full-body template: each day a chest and a side-delts exercise. Made once. */
    public static function template(): Template
    {
        $saved = Template::query()->where('name', 'Full body three')->first();
        if ($saved !== null) {
            return $saved;
        }
        $template = Template::factory()->create([
            'name' => 'Full body three', 'split' => Split::FullBody, 'sex' => SexFit::Any,
            'phase_fit' => [PhaseFit::Cut, PhaseFit::Maintain], 'priorities' => ['chest' => 'grow', 'sideDelts' => 'emphasize'],
        ]);
        foreach ([1, 2, 3] as $day) {
            foreach ([[MuscleGroup::Chest, 3], [MuscleGroup::SideDelts, 2]] as $position => [$muscle, $sets]) {
                TemplateSlot::factory()->create([
                    'template_id' => $template->id, 'day' => $day, 'position' => $position + 1, 'muscle_group' => $muscle,
                    'exercise_id' => Exercise::factory()->create(['muscle_group' => $muscle])->id, 'sets' => $sets,
                ]);
            }
        }

        return $template;
    }

    /** @param  array<string, mixed>  $answers  replaces the review's answers */
    public static function draft(User $user, array $answers = []): OnboardingDraft
    {
        $draft = OnboardingDraft::for($user);
        $draft->fill(array_replace([
            'step' => 14, 'goal' => 'fatLoss', 'finished_diet_recently' => false,
            'name' => 'Sam', 'sex' => 'female', 'birth_date' => '1990-04-09', 'height_cm' => 170.0, 'weight_kg' => 80.0,
            'screening' => [], 'uses_glp1' => false, 'body_fat_bucket' => 'low',
            'rate_percent_per_week' => -0.75, 'start_date' => '2026-10-11', 'weeks' => 12, 'experience' => 'intermediate',
            'training_days' => array_map(fn (int $weekday) => ['weekday' => $weekday, 'start_minutes' => 18 * 60, 'length_minutes' => 60], [1, 3, 5]),
            'training_plan' => 'template', 'template_id' => array_key_exists('template_id', $answers) ? null : self::template()->id,
            'weigh_ins' => array_map(fn (int $weekday) => ['weekday' => $weekday, 'minutes' => 7 * 60], [1, 3, 5]),
        ], $answers))->save();

        return $draft;
    }
}
