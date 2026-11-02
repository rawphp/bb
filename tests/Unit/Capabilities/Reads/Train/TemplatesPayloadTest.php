<?php

use App\Capabilities\Mesocycles\Data\TemplatesShowInput;
use App\Capabilities\Reads\Train\TemplatesPayload;
use App\Domain\Model\MuscleGroup;
use App\Domain\Model\PhaseType;
use App\Enums\PhaseFit;
use App\Enums\SexFit;
use App\Enums\Split;
use App\Models\Exercise;
use App\Models\Template;
use App\Models\TemplateSlot;
use Illuminate\Support\Collection;

// The template library (MESO-010, MESO-011, MESO-015) and a template's detail (MESO-012), from unsaved models.

/**
 * @param  list<string>  $fit
 * @param  array<string, string>  $priorities
 */
function libraryTemplate(int $id, string $name, int $days, Split $split, SexFit $sex, array $fit, array $priorities = []): Template
{
    $template = new Template([
        'name' => $name, 'split' => $split, 'days_per_week' => $days, 'sex' => $sex,
        'phase_fit' => array_map(PhaseFit::from(...), $fit), 'priorities' => $priorities,
        'day_labels' => array_map(fn (int $day) => "Day {$day}", range(1, $days)),
    ]);
    $template->id = $id;
    $template->setRelation('slots', new Collection);

    return $template;
}

/** @return Collection<int, Template> */
function library(): Collection
{
    return new Collection([
        libraryTemplate(1, 'Push Pull Legs', 6, Split::PushPullLegs, SexFit::Any, ['gain', 'maintain']),
        libraryTemplate(2, 'Glute Specialisation', 4, Split::Specialisation, SexFit::Female, ['gain']),
        libraryTemplate(3, 'Full Body Cut', 3, Split::FullBody, SexFit::Any, ['cut']),
        libraryTemplate(4, 'Arm Specialisation', 3, Split::Specialisation, SexFit::Male, ['gain']),
        libraryTemplate(5, 'Upper Lower', 4, Split::UpperLower, SexFit::Any, ['cut', 'gain', 'maintain']),
    ]);
}

/** @param  array<string, mixed>  $input */
function templates(array $input = [], ?PhaseType $now = null, ?Template $detail = null): array
{
    return TemplatesPayload::build(library(), TemplatesShowInput::from($input), $now, $detail)->toArray();
}

it('MESO-010 MESO-015 lists every template by days then name, with its days and split', function () {
    $data = templates(['phase' => 'all']);

    expect(array_column($data['rows'], 'name'))->toBe(['Arm Specialisation', 'Full Body Cut', 'Glute Specialisation', 'Upper Lower', 'Push Pull Legs'])
        ->and($data['rows'][0])->toBe(['id' => '4', 'name' => 'Arm Specialisation', 'summary' => '3 days/week · Specialisation'])
        ->and(array_column($data['rows'], 'summary'))->toContain('6 days/week · Push/pull/legs', '4 days/week · Upper/lower', '3 days/week · Full body')
        ->and($data['has_active_filters'])->toBeFalse()
        ->and($data['empty_text'])->toBeNull()
        ->and($data['detail'])->toBeNull();
});

it('MESO-011 the phase filter opens on the phase in force; a mini-cut reads as Cut', function () {
    $data = templates(now: PhaseType::MiniCut);
    $phase = $data['filters'][3];

    expect(array_column($data['rows'], 'name'))->toBe(['Full Body Cut', 'Upper Lower'])
        ->and($phase['key'])->toBe('phase')
        ->and($phase['label'])->toBe('Phase: Cut')
        ->and($phase['active'])->toBeTrue()
        ->and(array_column($phase['options'], 'value'))->toBe(['all', 'cut', 'gain', 'maintain'])
        ->and(array_column($phase['options'], 'selected'))->toBe([false, true, false, false])
        ->and($data['has_active_filters'])->toBeTrue();
});

it('MESO-011 with no phase in force, or with All picked, the phase filter is off', function () {
    $phase = templates()['filters'][3];

    expect($phase['label'])->toBe('Phase: All')
        ->and($phase['active'])->toBeFalse()
        ->and(array_column($phase['options'], 'selected'))->toBe([true, false, false, false])
        ->and(count(templates(['phase' => 'all'], PhaseType::MuscleGain)['rows']))->toBe(5);
});

it('MESO-011 filters by days per week, sex, whole body or emphasis, and phase fit', function (array $input, array $names) {
    expect(array_column(templates($input)['rows'], 'name'))->toBe($names);
})->with([
    'days' => [['days' => '4'], ['Glute Specialisation', 'Upper Lower']],
    // A template for anyone fits either sex.
    'female' => [['sex' => 'female'], ['Full Body Cut', 'Glute Specialisation', 'Upper Lower', 'Push Pull Legs']],
    'emphasis' => [['focus' => 'emphasis'], ['Arm Specialisation', 'Glute Specialisation']],
    'whole body' => [['focus' => 'whole_body'], ['Full Body Cut', 'Upper Lower', 'Push Pull Legs']],
    'maintain' => [['phase' => 'maintain'], ['Upper Lower', 'Push Pull Legs']],
    'all together' => [['days' => '4', 'sex' => 'male', 'focus' => 'whole_body', 'phase' => 'gain'], ['Upper Lower']],
]);

it('MESO-011 each filter says what it is set to and offers All first', function () {
    $filters = templates(['days' => '6', 'sex' => 'male', 'focus' => 'emphasis'])['filters'];

    expect(array_column($filters, 'key'))->toBe(['days', 'sex', 'focus', 'phase'])
        ->and(array_column($filters, 'label'))->toBe(['Days: 6', 'Sex: Male', 'Focus: Emphasis', 'Phase: All'])
        ->and(array_column($filters, 'active'))->toBe([true, true, true, false])
        ->and($filters[0]['options'][0])->toBe(['value' => null, 'label' => 'All', 'selected' => false])
        ->and(array_column($filters[0]['options'], 'label'))->toBe(['All', '2 days/week', '3 days/week', '4 days/week', '5 days/week', '6 days/week'])
        ->and(array_column($filters[1]['options'], 'label'))->toBe(['All', 'Female', 'Male'])
        ->and(array_column($filters[2]['options'], 'label'))->toBe(['All', 'Whole body', 'Emphasis']);
});

it('MESO-011 says so when no template matches', function () {
    $data = templates(['days' => '2']);

    expect($data['rows'])->toBe([])
        ->and($data['empty_text'])->toBe('No templates match these filters.');
});

it('MESO-012 MESO-030 detail shows days, exercises by muscle slot, the template\'s priorities and weekly sets per muscle', function () {
    $template = libraryTemplate(7, 'Full Body 2-Day', 2, Split::FullBody, SexFit::Any, ['gain'], ['chest' => 'emphasize', 'quads' => 'grow', 'abs' => 'maintain']);
    $template->day_labels = ['Full Body A', 'Full Body B'];
    $slot = function (int $day, string $exercise, MuscleGroup $muscle, int $sets): TemplateSlot {
        $slot = new TemplateSlot(['day' => $day, 'position' => 1, 'muscle_group' => $muscle, 'sets' => $sets]);

        return $slot->setRelation('exercise', new Exercise(['name' => $exercise, 'muscle_group' => $muscle]));
    };
    $template->setRelation('slots', new Collection([
        $slot(1, 'Barbell Back Squat', MuscleGroup::Quads, 3),
        $slot(1, 'Barbell Bench Press', MuscleGroup::Chest, 4),
        $slot(2, 'Incline Dumbbell Press', MuscleGroup::Chest, 1),
    ]));

    $detail = templates(detail: $template)['detail'];

    expect($detail['id'])->toBe('7')
        ->and($detail['summary'])->toBe('2 days/week · Full body')
        ->and(array_column($detail['days'], 'label'))->toBe(['Full Body A', 'Full Body B'])
        ->and($detail['days'][0]['slots'][1])->toBe(['exercise_name' => 'Barbell Bench Press', 'muscle_title' => 'Chest', 'sets_text' => '4 sets'])
        ->and($detail['days'][1]['slots'])->toBe([['exercise_name' => 'Incline Dumbbell Press', 'muscle_title' => 'Chest', 'sets_text' => '1 set']])
        // Only the muscles the template gives a priority, in MESO-033 order.
        ->and(array_column($detail['priorities'], 'muscle'))->toBe(['chest', 'quads', 'abs'])
        ->and($detail['priorities'][0])->toBe(['muscle' => 'chest', 'muscle_title' => 'Chest', 'priority' => 'emphasize', 'priority_title' => 'Emphasize', 'bars' => 3])
        ->and($detail['weekly_sets'])->toBe([
            ['muscle_title' => 'Chest', 'sets_text' => '5 sets'],
            ['muscle_title' => 'Quads', 'sets_text' => '3 sets'],
        ]);
});
