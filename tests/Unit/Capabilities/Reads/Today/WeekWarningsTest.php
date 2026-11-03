<?php

use App\Capabilities\Reads\Data\Today\Status;
use App\Capabilities\Reads\Today\WeekWarnings;
use App\Domain\Model\PhaseType;

// SCH-062: the week strip's "Too much cardio" warning, from the week's Other activity intensities.

/** @return list<string> */
function intensities(int $hard, int $moderate = 0, int $light = 0): array
{
    return [...array_fill(0, $hard, 'hard'), ...array_fill(0, $moderate, 'moderate'), ...array_fill(0, $light, 'light')];
}

/** @param list<Status> $warnings */
function warningRows(array $warnings): array
{
    return array_map(fn (Status $status) => $status->toArray(), $warnings);
}

it('SCH-062 5 hard Other activities in Fat loss warn, in the warning tone', function () {
    expect(warningRows(WeekWarnings::cardio(PhaseType::FatLoss, intensities(5))))->toBe([[
        'text' => 'Too much cardio hurts lifting recovery. That makes 5 hard sessions this week; more than 4 is a lot during fat loss.',
        'tone' => 'warning',
    ]]);
});

it('SCH-062 exactly 4 hard Other activities do not warn', function () {
    expect(WeekWarnings::cardio(PhaseType::FatLoss, intensities(4)))->toBe([]);
});

it('SCH-062 light and moderate Other activities do not count', function () {
    expect(WeekWarnings::cardio(PhaseType::FatLoss, intensities(4, moderate: 3, light: 2)))->toBe([]);
});

it('SCH-062 PLAN-009 Mini-cut warns as Fat loss does', function () {
    expect(warningRows(WeekWarnings::cardio(PhaseType::MiniCut, intensities(6))))->toBe([[
        'text' => 'Too much cardio hurts lifting recovery. That makes 6 hard sessions this week; more than 4 is a lot during fat loss.',
        'tone' => 'warning',
    ]]);
});

it('SCH-062 Maintenance, Muscle gain and no phase never warn', function (?PhaseType $phase) {
    expect(WeekWarnings::cardio($phase, intensities(9)))->toBe([]);
})->with([
    'Maintenance' => [PhaseType::Maintenance],
    'Muscle gain' => [PhaseType::MuscleGain],
    'no phase' => [null],
]);

it('SCH-062 the warning never blocks a write', function () {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS));
    $referencing = [];
    foreach ($files as $file) {
        if ($file->getExtension() === 'php' && str_contains((string) file_get_contents($file->getPathname()), 'cardioWarning')) {
            $referencing[] = str_replace(base_path().'/', '', $file->getPathname());
        }
    }
    sort($referencing);

    expect($referencing)->toBe(['app/Capabilities/Reads/Today/WeekWarnings.php', 'app/Domain/Energy/EnergyRules.php']);
});
