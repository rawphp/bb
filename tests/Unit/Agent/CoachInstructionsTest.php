<?php

use App\Agent\CoachInstructions;
use App\Agent\RegistryToolCatalog;
use App\Capabilities\Support\HostDefinition;
use Rawphp\Capabilities\Registry\CapabilityRegistry;

function destructiveAgentTools(): array
{
    $registry = app(CapabilityRegistry::class);

    return array_values(array_filter(
        app(RegistryToolCatalog::class)->names(),
        fn (string $name): bool => HostDefinition::isDestructive($registry->get($name)),
    ));
}

function instructionsFile(): string
{
    return file_get_contents(resource_path('coach/instructions.md'));
}

it('AGT-010 lists exactly the destructive tools on the agent profile, sorted', function () {
    $text = app(CoachInstructions::class)->text();
    preg_match('/## Tools that need the user\'s say-so first\n\n((?:- \S+\n?)+)\z/', $text, $found);
    $listed = array_map(fn (string $line): string => substr($line, 2), explode("\n", trim($found[1] ?? '')));

    expect($listed)->toBe(destructiveAgentTools())
        ->and($listed)->toContain('mesocycle_end', 'mesocycle_copy')
        ->and($listed)->toBe(collect($listed)->sort()->values()->all());
});

it('AGT-024 gives the same instructions on every call, with no per-user or per-time data', function () {
    $first = app(CoachInstructions::class)->text();
    $this->travel(2)->days();

    expect(app(CoachInstructions::class)->text())->toBe($first)
        ->and($first)->toStartWith(trim(instructionsFile()))
        ->and($first)->not->toContain('Now:');
});

it('AGT-011 drops the MesoPrep-only commands from the instructions', function (string $word) {
    expect(instructionsFile())->not->toContain($word);
})->with(['run_coach_command', 'submit_coach_output', 'context_pack', 'allow_logged_edits', 'proposal', 'MesoPrep', 'meal_index', 'command_type']);

it('AGT-003 names only registered capabilities in the instructions', function () {
    preg_match_all('/`([^`]+)`/', instructionsFile(), $spans);
    $names = [];
    foreach ($spans[1] as $span) {
        if (preg_match('/^(?:confirm: )?([a-z][a-z0-9]*_[a-z0-9_]+)(?:\{.*)?$/', $span, $found) === 1) {
            $names[] = $found[1];
        }
    }
    $registry = app(CapabilityRegistry::class)->all();

    expect($names)->toContain('undo_apply', 'mesocycle_end')
        ->and(array_values(array_filter(array_unique($names), fn (string $name): bool => ! isset($registry[$name]))))->toBe([]);
});

it('AGT-012 AGT-014 AGT-015 states the logging, refusal and Learn rules', function () {
    $text = instructionsFile();

    expect($text)->toContain('Never log a set, a meal check-in or a weigh-in the user did not report in chat')
        ->and($text)->toContain('show the user its message as given')
        ->and($text)->toContain('from the Learn articles, and link the article');
});
