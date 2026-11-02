<?php

use App\Domain\Model\MuscleGroup;
use App\Domain\Model\Priority;
use App\Enums\PhaseFit;
use App\Enums\SfrRating;
use App\Enums\Split;
use App\Models\Exercise;
use App\Models\Template;
use App\Models\TemplateSlot;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\ExerciseSeeder;
use Database\Seeders\TemplateSeeder;
use Illuminate\Database\Eloquent\Collection;

function seedTemplateLibrary(): void
{
    test()->seed(ExerciseSeeder::class);
    test()->seed(TemplateSeeder::class);
}

/** @return Collection<int, Template> */
function seededTemplates(): Collection
{
    seedTemplateLibrary();

    return Template::with('slots.exercise')->get();
}

/**
 * Distinct days each muscle is trained on, for one template.
 *
 * @return array<string, int>
 */
function muscleDays(Template $template): array
{
    return $template->slots->groupBy(fn (TemplateSlot $slot) => $slot->muscle_group->value)
        ->map(fn ($slots) => $slots->pluck('day')->unique()->count())
        ->all();
}

const MUSCLE_MV = ['chest' => 8, 'back' => 8, 'quads' => 6, 'hamstrings' => 4, 'biceps' => 5, 'triceps' => 4, 'calves' => 6];

/**
 * Weekly sets per muscle for one template.
 *
 * @return array<string, int>
 */
function weeklySets(Template $template): array
{
    return $template->slots->groupBy(fn (TemplateSlot $slot) => $slot->muscle_group->value)
        ->map(fn ($slots) => (int) $slots->sum('sets'))
        ->all();
}

it('MESO-010 seeds at least 45 templates from 2-day full body to 6-day specialisation', function () {
    $templates = seededTemplates();

    expect($templates->count())->toBeGreaterThanOrEqual(45)
        ->and($templates->pluck('name')->unique()->count())->toBe($templates->count())
        ->and($templates->pluck('days_per_week')->unique()->sort()->values()->all())->toBe([2, 3, 4, 5, 6])
        ->and($templates->contains(fn ($t) => $t->days_per_week === 2 && $t->split === Split::FullBody))->toBeTrue()
        ->and($templates->contains(fn ($t) => $t->days_per_week === 6 && $t->split === Split::Specialisation))->toBeTrue();
});

it('MESO-010 seeding twice does not duplicate templates or slots', function () {
    seedTemplateLibrary();
    $templates = Template::count();
    $slots = TemplateSlot::count();

    $this->seed(TemplateSeeder::class);

    expect(Template::count())->toBe($templates)
        ->and(TemplateSlot::count())->toBe($slots)
        ->and($slots)->toBeGreaterThan(0);
});

it('MESO-010 re-seeding replaces a template\'s edited slots', function () {
    seedTemplateLibrary();
    $template = Template::where('name', 'Full Body 3-Day Lean')->firstOrFail();
    $original = $template->slots()->count();
    $template->slots()->first()->delete();
    $template->slots()->create([
        'day' => 1, 'position' => 99, 'muscle_group' => MuscleGroup::Abs,
        'exercise_id' => Exercise::where('name', 'Plank')->value('id'), 'sets' => 9,
    ]);

    $this->seed(TemplateSeeder::class);

    expect($template->slots()->count())->toBe($original)
        ->and($template->slots()->where('sets', 9)->exists())->toBeFalse();
});

it('MESO-010 seeding fails loudly when a template names an unknown exercise', function () {
    // No exercises seeded, so every name in the data file is unknown.
    $this->seed(TemplateSeeder::class);
})->throws(RuntimeException::class);

it('MESO-012 every slot references a real library exercise of the slot\'s muscle group', function () {
    $templates = seededTemplates();

    foreach ($templates as $template) {
        foreach ($template->slots as $slot) {
            expect($slot->exercise)->not->toBeNull("{$template->name} has a slot without an exercise")
                ->and($slot->exercise->user_id)->toBeNull()
                ->and($slot->exercise->muscle_group)->toBe($slot->muscle_group, "{$template->name}: {$slot->exercise->name}");
        }
    }
});

it('MESO-012 every day has slots, day labels match days per week and each day holds 4 to 8 slots', function () {
    foreach (seededTemplates() as $template) {
        expect($template->day_labels)->toHaveCount($template->days_per_week, $template->name)
            ->and(collect($template->day_labels)->unique()->count())->toBe($template->days_per_week);

        foreach (range(1, $template->days_per_week) as $day) {
            $count = $template->slots->where('day', $day)->count();
            expect($count)->toBeGreaterThanOrEqual(4, "{$template->name} day {$day}")
                ->and($count)->toBeLessThanOrEqual(8, "{$template->name} day {$day}");
        }

        expect($template->slots->pluck('day')->max())->toBe($template->days_per_week);
    }
});

it('MESO-012 positions within a day are 1..n with no gaps', function () {
    foreach (seededTemplates() as $template) {
        foreach ($template->slots->groupBy('day') as $day => $slots) {
            expect($slots->pluck('position')->sort()->values()->all())->toBe(range(1, $slots->count()), "{$template->name} day {$day}");
        }
    }
});

it('MESO-015 every split label is present', function () {
    $splits = seededTemplates()->pluck('split')->map->value->unique()->sort()->values()->all();

    expect($splits)->toBe(['full_body', 'push_pull_legs', 'specialisation', 'upper_lower']);
});

it('MESO-011 sex fit covers any, female and male', function () {
    $sexes = seededTemplates()->pluck('sex')->map->value->unique()->sort()->values()->all();

    expect($sexes)->toBe(['any', 'female', 'male']);
});

it('MESO-012 priorities cover all 14 muscle groups and every slot has at least 2 sets', function () {
    foreach (seededTemplates() as $template) {
        expect(array_keys($template->priorities))->toEqualCanonicalizing(array_map(fn ($m) => $m->value, MuscleGroup::cases()), $template->name);

        foreach ($template->priorities as $priority) {
            expect(Priority::tryFrom($priority))->not->toBeNull();
        }

        foreach ($template->slots as $slot) {
            expect($slot->sets)->toBeGreaterThanOrEqual(2, "{$template->name}: {$slot->exercise->name}");
        }
    }
});

it('MESO-030 trained muscles grow, untrained ones maintain, and emphasis appears only in specialisations', function () {
    foreach (seededTemplates() as $template) {
        $trained = $template->slots->pluck('muscle_group')->map->value->unique()->all();
        $isResensitization = str_contains($template->name, 'Resensitization');

        foreach ($template->priorities as $muscle => $priority) {
            if (! in_array($muscle, $trained, true)) {
                expect($priority)->toBe('maintain', "{$template->name}: untrained {$muscle}");
            } elseif ($isResensitization) {
                expect($priority)->toBe('maintain', "{$template->name}: {$muscle}");
            } elseif ($template->split !== Split::Specialisation) {
                expect($priority)->toBe('grow', "{$template->name}: {$muscle}");
            } else {
                expect($priority)->toBeIn(['emphasize', 'grow'], "{$template->name}: {$muscle}");
            }
        }
    }
});

it('MESO-021 specialisations suit muscle gain and emphasize 1 or 2 muscles trained at least twice a week', function () {
    $specialisations = seededTemplates()->where('split', Split::Specialisation);
    $targets = [];

    expect($specialisations->count())->toBeGreaterThanOrEqual(10);

    foreach ($specialisations as $template) {
        $emphasized = collect($template->priorities)->filter(fn ($p) => $p === 'emphasize')->keys();

        expect($template->phase_fit)->toContain(PhaseFit::Gain)
            ->and($template->days_per_week)->toBeGreaterThanOrEqual(3)
            ->and($emphasized->count())->toBeBetween(1, 2, $template->name);

        foreach ($emphasized as $muscle) {
            expect(muscleDays($template)[$muscle] ?? 0)->toBeGreaterThanOrEqual(2, "{$template->name}: {$muscle}");
            $targets[$muscle] = true;
        }
    }

    expect(array_keys($targets))->toContain('chest', 'back', 'sideDelts', 'biceps', 'triceps', 'quads', 'glutes', 'hamstrings', 'calves');
});

it('MESO-016 fat loss suits full-body templates from 2 to 5 days that avoid low stimulus-to-fatigue exercises', function () {
    $cut = seededTemplates()->filter(fn ($t) => $t->phase_fit->contains(PhaseFit::Cut));

    expect($cut->count())->toBeGreaterThanOrEqual(5)
        ->and($cut->pluck('days_per_week')->unique()->sort()->values()->all())->toBe([2, 3, 4, 5]);

    foreach ($cut as $template) {
        expect($template->split)->toBe(Split::FullBody);

        foreach ($template->slots as $slot) {
            expect($slot->exercise->sfr_rating)->not->toBe(SfrRating::Low, "{$template->name}: {$slot->exercise->name}");
        }
    }
});

it('PH-036 has 2 or 3 resensitization templates: full body, 2 to 3 days, all Maintain, 1 to 2 sessions per muscle, at least MV sets', function () {
    $resensitization = seededTemplates()->filter(fn ($t) => str_contains($t->name, 'Resensitization'));

    expect($resensitization->count())->toBeBetween(2, 3);

    foreach ($resensitization as $template) {
        expect($template->split)->toBe(Split::FullBody)
            ->and($template->days_per_week)->toBeBetween(2, 3)
            ->and($template->phase_fit->map->value->all())->toBe(['maintain'])
            ->and(collect($template->priorities)->unique()->values()->all())->toBe(['maintain']);

        foreach ($template->slots as $slot) {
            expect($slot->sets)->toBeGreaterThanOrEqual(2, "{$template->name}: {$slot->exercise->name}");
        }

        foreach (muscleDays($template) as $muscle => $days) {
            expect($days)->toBeBetween(1, 2, "{$template->name}: {$muscle}");
        }

        foreach (weeklySets($template) as $muscle => $sets) {
            expect($sets)->toBeGreaterThanOrEqual(MUSCLE_MV[$muscle] ?? 0, "{$template->name}: {$muscle} weekly sets vs MV");
        }
    }
});

it('TBL-012 TBL-021 outside resensitization every MV muscle is trained on at least 2 days and gets at least its MV in weekly sets', function () {
    foreach (seededTemplates() as $template) {
        if (str_contains($template->name, 'Resensitization')) {
            continue;
        }

        $days = muscleDays($template);
        $sets = weeklySets($template);

        foreach (MUSCLE_MV as $muscle => $mv) {
            expect($days[$muscle] ?? 0)->toBeGreaterThanOrEqual(2, "{$template->name}: {$muscle} days");
            expect($sets[$muscle] ?? 0)->toBeGreaterThanOrEqual($mv, "{$template->name}: {$muscle} weekly sets vs MV");
        }
    }
});

it('MESO-021 in every specialisation each emphasized muscle gets at least as many weekly sets as every grow muscle', function () {
    foreach (seededTemplates()->where('split', Split::Specialisation) as $template) {
        $sets = weeklySets($template);
        $emphasized = collect($template->priorities)->filter(fn ($p) => $p === 'emphasize')->keys();
        $grow = collect($template->priorities)->filter(fn ($p) => $p === 'grow')->keys();

        foreach ($emphasized as $emphasizedMuscle) {
            foreach ($grow as $growMuscle) {
                expect($sets[$emphasizedMuscle])->toBeGreaterThanOrEqual($sets[$growMuscle] ?? 0, "{$template->name}: {$emphasizedMuscle} vs {$growMuscle}");
            }
        }
    }
});

it('MESO-010 re-seeding removes library templates that are no longer in the data file', function () {
    seedTemplateLibrary();
    $stale = Template::factory()->create(['name' => 'Removed From Data File']);
    TemplateSlot::factory()->for($stale)->create(['position' => 1]);
    $library = Template::count() - 1;

    $this->seed(TemplateSeeder::class);

    expect(Template::where('name', 'Removed From Data File')->exists())->toBeFalse()
        ->and(Template::count())->toBe($library)
        ->and(TemplateSlot::where('template_id', $stale->id)->exists())->toBeFalse();
});

it('MESO-046 compounds come before isolations within each day', function () {
    foreach (seededTemplates() as $template) {
        foreach ($template->slots->groupBy('day') as $day => $slots) {
            $flags = $slots->sortBy('position')->map(fn ($s) => $s->exercise->is_compound)->values()->all();
            $firstIsolation = array_search(false, $flags, true);

            if ($firstIsolation !== false) {
                expect(in_array(true, array_slice($flags, $firstIsolation), true))->toBeFalse("{$template->name} day {$day}");
            }
        }
    }
});

it('TBL-021 weekly sets for a muscle never exceed its MRV', function () {
    $mrv = [
        'chest' => 22, 'back' => 25, 'quads' => 20, 'hamstrings' => 20, 'glutes' => 16, 'sideDelts' => 26, 'rearDelts' => 26,
        'frontDelts' => 12, 'biceps' => 26, 'triceps' => 18, 'calves' => 20, 'abs' => 25, 'traps' => 26, 'forearms' => 25,
    ];

    foreach (seededTemplates() as $template) {
        foreach ($template->slots->groupBy(fn ($s) => $s->muscle_group->value) as $muscle => $slots) {
            expect($slots->sum('sets'))->toBeLessThanOrEqual($mrv[$muscle], "{$template->name}: {$muscle}");
        }
    }
});

// Endpoint

it('MESO-010 is part of the default seed', function () {
    $this->seed(DatabaseSeeder::class);

    expect(Template::count())->toBeGreaterThanOrEqual(45);
});
