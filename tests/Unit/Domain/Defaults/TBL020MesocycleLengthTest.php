<?php

use App\Domain\Defaults\TBL020MesocycleLength;
use App\Domain\Model\ClosedRange;
use App\Domain\Model\ExperienceLevel;

it('TBL-020 mesocycle length by experience', function (ExperienceLevel $experience, int $default, ClosedRange $allowed) {
    $row = TBL020MesocycleLength::row($experience);

    expect($row->defaultWeeks)->toBe($default)
        ->and($row->allowedWeeks)->toEqual($allowed);
})->with([
    'beginner' => [ExperienceLevel::Beginner, 6, new ClosedRange(5, 8)],
    'intermediate' => [ExperienceLevel::Intermediate, 5, new ClosedRange(5, 8)],
    'advanced' => [ExperienceLevel::Advanced, 5, new ClosedRange(4, 7)],
]);

it('TBL-020 resensitization mesocycle length at any level', function () {
    expect(TBL020MesocycleLength::resensitization()->defaultWeeks)->toBe(4)
        ->and(TBL020MesocycleLength::resensitization()->allowedWeeks)->toEqual(new ClosedRange(3, 4));
});
