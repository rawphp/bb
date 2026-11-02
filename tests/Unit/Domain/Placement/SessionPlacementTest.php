<?php

use App\Domain\Model\PlanWeek;
use App\Domain\Model\Session;
use App\Domain\Placement\ClockTime;
use App\Domain\Placement\ScheduledSession;
use App\Domain\Placement\SessionPlacement;
use App\Domain\Placement\TrainingDay;
use App\Domain\Placement\WorkoutTime;

$date = fn (string $ymd): DateTimeImmutable => new DateTimeImmutable($ymd, new DateTimeZone('Australia/Brisbane'));

$sessions = array_map(fn (string $name) => new Session($name, $name, []), ['Upper A', 'Lower A', 'Upper B']);

// Mon, Wed, Fri at 17:30 for an hour.
$training = array_map(fn (int $weekday) => new TrainingDay($weekday, new WorkoutTime(ClockTime::at(17, 30), 60)), [2, 4, 6]);

$names = fn (array $placed): array => array_map(fn (ScheduledSession $s) => $s->session->name, $placed);
$dates = fn (array $placed): array => array_map(fn (ScheduledSession $s) => $s->date->format(DATE_ATOM), $placed);
$atoms = fn (array $dates): array => array_map(fn (DateTimeImmutable $d) => $d->format(DATE_ATOM), $dates);

it('SCH-040 sessions in order on training weekdays at their times', function () use ($date, $sessions, $training, $names, $dates, $atoms) {
    // 2026-10-05 is a Monday; the plan week starts on its review day.
    $placed = SessionPlacement::place($sessions, $training, new PlanWeek($date('2026-10-05')));

    expect($names($placed))->toBe(['Upper A', 'Lower A', 'Upper B'])
        ->and($dates($placed))->toBe($atoms([$date('2026-10-05'), $date('2026-10-07'), $date('2026-10-09')]))
        ->and(array_map(fn (ScheduledSession $s) => (string) $s->time->start, $placed))->toBe(['17:30', '17:30', '17:30'])
        ->and(array_map(fn (ScheduledSession $s) => $s->time->lengthMinutes, $placed))->toBe([60, 60, 60]);
});

it('SCH-040 order follows the plan week, not the calendar week', function () use ($date, $sessions, $training, $names, $dates, $atoms) {
    // Review day Wednesday: the week runs Wed to Tue, so Monday is its last training day.
    $placed = SessionPlacement::place($sessions, $training, new PlanWeek($date('2026-10-07')));

    expect($names($placed))->toBe(['Upper A', 'Lower A', 'Upper B'])
        ->and($dates($placed))->toBe($atoms([$date('2026-10-07'), $date('2026-10-09'), $date('2026-10-12')]));
});

it('SCH-040 each training day keeps its own time', function () use ($date, $sessions, $names, $dates, $atoms) {
    $days = [
        new TrainingDay(3, new WorkoutTime(ClockTime::at(6), 45)),
        new TrainingDay(7, new WorkoutTime(ClockTime::at(10, 15), 90)),
    ];

    $placed = SessionPlacement::place($sessions, $days, new PlanWeek($date('2026-10-05')));

    expect($names($placed))->toBe(['Upper A', 'Lower A'])
        ->and($dates($placed))->toBe($atoms([$date('2026-10-06'), $date('2026-10-10')]))
        ->and(array_map(fn (ScheduledSession $s) => $s->time, $placed))->toEqual(array_map(fn (TrainingDay $d) => $d->time, $days))
        ->and($placed[0]->isSkipped)->toBeFalse();
});
