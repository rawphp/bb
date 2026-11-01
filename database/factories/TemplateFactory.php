<?php

namespace Database\Factories;

use App\Enums\MuscleGroup;
use App\Enums\MusclePriority;
use App\Enums\PhaseFit;
use App\Enums\SexFit;
use App\Enums\Split;
use App\Models\Template;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Template> */
class TemplateFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(3, true),
            'split' => Split::FullBody,
            'days_per_week' => 3,
            'sex' => SexFit::Any,
            'phase_fit' => [PhaseFit::Maintain],
            'priorities' => collect(MuscleGroup::cases())
                ->mapWithKeys(fn (MuscleGroup $muscle) => [$muscle->value => MusclePriority::Maintain->value])
                ->all(),
            'day_labels' => ['Day 1', 'Day 2', 'Day 3'],
        ];
    }

    public function days(int $count): static
    {
        return $this->state(fn () => [
            'days_per_week' => $count,
            'day_labels' => array_map(fn (int $day) => "Day {$day}", range(1, $count)),
        ]);
    }
}
