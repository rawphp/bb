<?php

use Rawphp\Capabilities\Registry\CapabilityRegistry;
use Rawphp\CapabilitiesAi\Contracts\ToolCatalog;

it('CAP-031 leaves the three coach_ capabilities out of the agent\'s tool catalog', function () {
    $coach = ['coach_changes_list', 'coach_enabled_set', 'coach_conversation_clear'];
    $registered = array_keys(app(CapabilityRegistry::class)->all());
    $tools = array_column(app(ToolCatalog::class)->toolsForTurn('CONV', 'TURN'), 'name');

    expect(array_intersect($coach, $registered))->toBe($coach)
        ->and($tools)->not->toBe([])
        ->and(array_intersect($coach, $tools))->toBe([]);
});
