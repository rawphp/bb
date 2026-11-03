<?php

namespace Tests\Unit\Capabilities\Reads\Onboarding;

use App\Domain\Model\MuscleGroup;
use App\Enums\PhaseFit;
use App\Enums\SexFit;
use App\Enums\Split;
use App\Models\OnboardingDraft;
use App\Models\Template;
use App\Models\TemplateSlot;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * The onboarding_show fixtures' person, saved through App\Models\OnboardingDraft. The clock is Saturday 2026-10-10, so
 * tomorrow is Sunday the 11th. Sam is a woman born 9 April 1990, 170.18 cm (5 ft 7 in) and 80 kg (176.4 lb), on a
 * fat-loss phase of 12 weeks from tomorrow, training Monday, Wednesday and Friday at 6 PM for 60 minutes, weighing in
 * on the same days at 7 AM and commuting 8 to 9 AM on weekdays. The library holds two 3-day fat-loss templates: a
 * full-body one (the recommendation) and an upper / lower one.
 */
final class OnboardingShowWorld
{
    public const TODAY = '2026-10-10 09:00:00';

    public static function freezeClock(): void
    {
        Carbon::setTestNow(self::TODAY);
    }

    /**
     * Sam's draft on `$step`, with `$changes` over her answers; the user, and the ids of the two templates.
     *
     * @param  array<string, mixed>  $changes
     * @return array{user: User, full: int, upper: int}
     */
    public static function sam(int $step, array $changes = []): array
    {
        $world = self::library();
        OnboardingDraft::for($world['user'])->fill([
            'step' => $step,
            'goal' => 'fatLoss',
            'finished_diet_recently' => false,
            'name' => 'Sam',
            'sex' => 'female',
            'birth_date' => '1990-04-09',
            'height_cm' => 170.18,
            'weight_kg' => 80.0,
            'weight_unit' => 'lb',
            'height_unit' => 'feetInches',
            'uses_glp1' => false,
            'body_fat_bucket' => 'low',
            'rate_percent_per_week' => -0.75,
            'start_date' => '2026-10-11',
            'weeks' => 12,
            'experience' => 'intermediate',
            'training_days' => array_map(fn (int $weekday) => ['weekday' => $weekday, 'start_minutes' => 18 * 60, 'length_minutes' => 60], [1, 3, 5]),
            'training_plan' => 'template',
            'template_id' => $world['full'],
            'busy_periods' => [['id' => '01JBX0Q5ZK3J7Y8T6V2N4M1C9D', 'label' => 'Commute', 'start_minutes' => 480, 'end_minutes' => 540, 'weekdays' => [1, 2, 3, 4, 5]]],
            'weigh_ins' => array_map(fn (int $weekday) => ['weekday' => $weekday, 'minutes' => 7 * 60], [1, 3, 5]),
            ...$changes,
        ])->save();

        return $world;
    }

    /**
     * A user with no draft row, and the library.
     *
     * @return array{user: User, full: int, upper: int}
     */
    public static function library(): array
    {
        $full = self::template('Full Body 3-Day Dumbbells', Split::FullBody);
        $upper = self::template('Upper / Lower 3-Day', Split::UpperLower);

        return ['user' => User::factory()->create(), 'full' => $full->id, 'upper' => $upper->id];
    }

    private static function template(string $name, Split $split): Template
    {
        $existing = Template::query()->where('name', $name)->first();
        if ($existing !== null) {
            return $existing;
        }

        $template = Template::factory()->days(3)->create([
            'name' => $name, 'split' => $split, 'sex' => SexFit::Any, 'phase_fit' => [PhaseFit::Cut],
        ]);
        foreach ([1, 2, 3] as $day) {
            foreach ([MuscleGroup::Chest, MuscleGroup::Quads] as $position => $muscle) {
                TemplateSlot::factory()->create([
                    'template_id' => $template->id, 'day' => $day, 'position' => $position + 1, 'muscle_group' => $muscle,
                ]);
            }
        }

        return $template;
    }
}
