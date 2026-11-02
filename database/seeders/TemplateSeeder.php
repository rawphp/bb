<?php

namespace Database\Seeders;

use App\Domain\Model\MuscleGroup;
use App\Domain\Model\Priority;
use App\Models\Exercise;
use App\Models\Template;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class TemplateSeeder extends Seeder
{
    /**
     * Upsert the template library by name, replace each template's slots and prune templates missing from the data file; safe to re-run.
     * Run after ExerciseSeeder: slots look exercises up by exact library name.
     */
    public function run(): void
    {
        $exercises = Exercise::whereNull('user_id')->get()->keyBy('name');

        DB::transaction(function () use ($exercises) {
            $library = require __DIR__.'/data/templates.php';

            foreach ($library as $row) {
                $slots = $this->slots($row, $exercises);
                $trained = collect($slots)->pluck('muscle_group')->unique()->all();

                $template = Template::updateOrCreate(['name' => $row['name']], [
                    'split' => $row['split'],
                    'days_per_week' => count($row['days']),
                    'sex' => $row['sex'],
                    'phase_fit' => $row['phase_fit'],
                    'priorities' => $this->priorities($row, $trained),
                    'day_labels' => array_keys($row['days']),
                ]);

                $template->slots()->delete();
                $template->slots()->createMany($slots);
            }

            // Templates renamed or removed in the data file stop being served; their slots cascade.
            Template::whereNotIn('name', array_column($library, 'name'))->delete();
        });
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  Collection<string, Exercise>  $exercises
     * @return list<array<string, mixed>>
     */
    private function slots(array $row, $exercises): array
    {
        $slots = [];

        foreach (array_values($row['days']) as $dayIndex => $lines) {
            foreach ($lines as $position => $line) {
                [$name, $sets] = explode('|', $line);
                $exercise = $exercises[$name]
                    ?? throw new RuntimeException("Template '{$row['name']}' uses unknown library exercise '{$name}'.");

                $slots[] = [
                    'day' => $dayIndex + 1,
                    'position' => $position + 1,
                    'muscle_group' => $exercise->muscle_group,
                    'exercise_id' => $exercise->id,
                    'sets' => (int) $sets,
                ];
            }
        }

        return $slots;
    }

    /**
     * Emphasised muscles emphasize, other trained muscles grow, untrained maintain;
     * a template flagged maintain_all (resensitization, PH-036) holds every muscle at maintain.
     *
     * @param  array<string, mixed>  $row
     * @param  list<MuscleGroup>  $trained
     * @return array<string, string>
     */
    private function priorities(array $row, array $trained): array
    {
        $priorities = [];

        foreach (MuscleGroup::cases() as $muscle) {
            $priority = match (true) {
                $row['maintain_all'] ?? false => Priority::Maintain,
                in_array($muscle->value, $row['emphasis'] ?? [], true) => Priority::Emphasize,
                in_array($muscle, $trained, true) => Priority::Grow,
                default => Priority::Maintain,
            };
            $priorities[$muscle->value] = $priority->value;
        }

        return $priorities;
    }
}
