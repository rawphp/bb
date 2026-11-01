<?php

namespace Database\Factories;

use App\Domain\Model\MuscleGroup;
use App\Models\Exercise;
use App\Models\Template;
use App\Models\TemplateSlot;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TemplateSlot> */
class TemplateSlotFactory extends Factory
{
    public function definition(): array
    {
        return [
            'template_id' => Template::factory(),
            'day' => 1,
            'position' => 1,
            'muscle_group' => MuscleGroup::Chest,
            'exercise_id' => Exercise::factory()->state(['muscle_group' => MuscleGroup::Chest]),
            'sets' => 3,
        ];
    }
}
