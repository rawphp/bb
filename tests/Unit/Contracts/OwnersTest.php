<?php

// tests/fixtures/schemas/<area>.owners.json maps each read and write of an
// area to the API issue that registers it (docs/plan/build-plan.md).

/** @return array<string, array<string, int>> area → name → issue */
function ownersFiles(): array
{
    $areas = [];
    foreach (glob(base_path('tests/fixtures/schemas/*.owners.json')) ?: [] as $path) {
        $areas[basename($path, '.owners.json')] = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }

    return $areas;
}

it('CAP-002 gives every contracted name one owner issue and a schema snapshot', function () {
    $seen = [];

    foreach (ownersFiles() as $area => $owners) {
        foreach ($owners as $name => $issue) {
            expect($name)->toMatch('/^[a-z][a-z0-9_]{0,63}$/');
            expect($issue)->toBeInt()->toBeGreaterThan(0);
            expect($seen)->not->toHaveKey($name, "{$name} is owned in {$area} and ".($seen[$name] ?? ''));
            $seen[$name] = $area;

            expect(base_path("tests/fixtures/schemas/{$name}.input.json"))->toBeFile();
            if (str_ends_with($name, '_show')) {
                expect(base_path("tests/fixtures/schemas/{$name}.output.json"))->toBeFile();
                expect(base_path("tests/fixtures/screens/{$name}.json"))->toBeFile();
            }
        }
    }
});

it('CAP-002 contracts the progress area\'s reads and writes to issues 87 to 89 and 232', function () {
    expect(ownersFiles()['progress'])->toBe([
        'exercise_history_show' => 89,
        'progress_show' => 89,
        'review_answer' => 87,
        'review_complete' => 87,
        'review_show' => 232,
        'steps_log' => 88,
        'weigh_in_delete' => 88,
        'weigh_in_log' => 88,
        'weigh_in_schedule_set' => 88,
        'weigh_in_update' => 88,
        'weigh_ins_show' => 88,
    ]);
});
