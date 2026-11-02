<?php

use App\Capabilities\Reads\Workout\WorkoutWords;

// FB-006: every Feedback question has a ? icon that explains it and how its answers change future sessions.

it('FB-006 each kind of feedback question has its own help, saying how its answers change next week, with the article', function (string $kind, string $title, string $change) {
    $help = WorkoutWords::feedbackHelp($kind);

    expect([$help->title, $help->article])->toBe([$title, 'feedback-questions'])
        ->and($help->body)->toContain($change);
})->with([
    'joint' => ['joint', 'Joint pain', 'keeps sets from being added to this exercise next week'],
    'soreness' => ['soreness', 'Soreness', 'can take more work next week'],
    'pump' => ['pump', 'Pump', 'pushes next week\'s sets up'],
    'volume' => ['volume', 'Volume', 'Not enough adds sets next week'],
]);
