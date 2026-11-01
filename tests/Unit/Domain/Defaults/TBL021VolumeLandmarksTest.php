<?php

use App\Domain\Defaults\TBL021VolumeLandmarks;
use App\Domain\Model\MuscleGroup;

dataset('tbl021', [
    'chest' => [MuscleGroup::Chest, 8, 10, 22],
    'back' => [MuscleGroup::Back, 8, 10, 25],
    'quads' => [MuscleGroup::Quads, 6, 8, 20],
    'hamstrings' => [MuscleGroup::Hamstrings, 4, 6, 20],
    'glutes' => [MuscleGroup::Glutes, 0, 0, 16],
    'side delts' => [MuscleGroup::SideDelts, 0, 8, 26],
    'rear delts' => [MuscleGroup::RearDelts, 0, 6, 26],
    'front delts' => [MuscleGroup::FrontDelts, 0, 0, 12],
    'biceps' => [MuscleGroup::Biceps, 5, 8, 26],
    'triceps' => [MuscleGroup::Triceps, 4, 6, 18],
    'calves' => [MuscleGroup::Calves, 6, 8, 20],
    'abs' => [MuscleGroup::Abs, 0, 0, 25],
    'traps' => [MuscleGroup::Traps, 0, 0, 26],
    'forearms' => [MuscleGroup::Forearms, 0, 0, 25],
]);

it('TBL-021 landmarks per muscle', function (MuscleGroup $muscle, int $mv, int $mev, int $mrv) {
    $landmarks = TBL021VolumeLandmarks::landmarks($muscle);

    expect([$landmarks->mv, $landmarks->mev, $landmarks->mrv])->toBe([$mv, $mev, $mrv]);
})->with('tbl021');

it('TBL-021 covers every muscle group', function () {
    expect(MuscleGroup::cases())->toHaveCount(14);
});

it('TBL-021 experience offsets and floors', function () {
    expect(TBL021VolumeLandmarks::BEGINNER_MEV_OFFSET)->toBe(-2)
        ->and(TBL021VolumeLandmarks::BEGINNER_MRV_OFFSET)->toBe(-4)
        ->and(TBL021VolumeLandmarks::ADVANCED_MEV_OFFSET)->toBe(2)
        ->and(TBL021VolumeLandmarks::STARTING_VOLUME_FLOOR_SETS)->toBe(0)
        ->and(TBL021VolumeLandmarks::MINIMUM_SETS_PER_SESSION_FOR_DIRECTLY_TRAINED_MUSCLE)->toBe(2);
});
