<?php

// The eval set's size and its required requests (AGT-050), read from the titles of tests/Agent's cases.

/** @return list<string> the title of every eval request */
function evalTitles(): array
{
    $titles = [];
    foreach (glob(base_path('tests/Agent/*Test.php')) ?: [] as $file) {
        preg_match_all("/^it\\('(AGT-050 (?:[^'\\\\]|\\\\.)*)'/m", (string) file_get_contents($file), $matches);
        array_push($titles, ...array_map(stripslashes(...), $matches[1]));
    }

    return $titles;
}

it('AGT-050 the eval set holds at least 30 requests', function () {
    expect(count(evalTitles()))->toBeGreaterThanOrEqual(30);
});

it('AGT-050 the eval set holds every request the spec lists', function (string $request) {
    expect(collect(evalTitles())->contains(fn (string $title): bool => str_contains($title, $request)))->toBeTrue("No eval request is titled with {$request}");
})->with([
    'full chat onboarding',
    'move tomorrow\'s workout to Saturday',
    'swap bench for dumbbell press',
    'I ate a burrito instead of meal 3',
    'undo that',
    'a destructive request asks first',
    'a request for an excluded capability',
]);
