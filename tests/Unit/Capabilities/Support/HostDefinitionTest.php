<?php

use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\WriteResult;
use Rawphp\Capabilities\Registry\CapabilityRegistry;
use Tests\Support\Fixtures\Rail\TallyInput;

it('CAP-011 records a write\'s affects in its catalog entry', function () {
    $definition = HostDefinition::write('workout_set_log', affects: ['workout_show', 'today_show', 'train_show'])
        ->description('Log a set.')
        ->cli('workout', 'set-log')
        ->input(TallyInput::class)
        ->run(fn () => new WriteResult(invalidates: []))
        ->toDefinition();

    expect(HostDefinition::affects($definition))->toBe(['workout_show', 'today_show', 'train_show'])
        ->and($definition->tags)->toBe(['affects:workout_show', 'affects:today_show', 'affects:train_show']);
});

it('CAP-011 lists affects in the catalog the client reads', function () {
    $registry = new CapabilityRegistry;
    HostDefinition::write('weigh_in_log', affects: ['weigh_ins_show'])
        ->description('Log a weigh-in.')
        ->cli('weigh-in', 'log')
        ->input(TallyInput::class)
        ->run(fn () => new WriteResult(invalidates: []))
        ->register($registry);

    $entry = collect($registry->catalog()->listEnvelope()['capabilities'])->firstWhere('name', 'weigh_in_log');

    expect($entry['tags'])->toBe(['affects:weigh_ins_show']);
});

it('CAP-011 refuses a write that affects no read', function () {
    HostDefinition::write('weigh_in_log', affects: []);
})->throws(InvalidArgumentException::class, 'weigh_in_log must list the reads it affects (CAP-011).');

it('CAP-011 refuses an affects entry that is not a capability name', function () {
    HostDefinition::write('weigh_in_log', affects: ['Weigh-ins']);
})->throws(InvalidArgumentException::class, 'weigh_in_log affects "Weigh-ins", which is not a capability name.');

it('CAP-010 gives a write the WriteResult output and every surface', function () {
    $definition = HostDefinition::write('weigh_in_log', affects: ['weigh_ins_show'])->input(TallyInput::class)->toDefinition();

    expect($definition->output)->toBe(WriteResult::class)
        ->and($definition->readOnly)->toBeFalse()
        ->and($definition->surfaces)->toBe(['http', 'job', 'mcp', 'cli', 'agent']);
});

it('CAP-011 gives a read no affects', function () {
    $definition = HostDefinition::read('weigh_ins_show')->toDefinition();

    expect($definition->readOnly)->toBeTrue()
        ->and($definition->surfaces)->toBe(['http', 'job', 'mcp', 'cli', 'agent'])
        ->and(HostDefinition::affects($definition))->toBe([]);
});

it('CAP-011 names each affects entry that is not a registered read', function () {
    $registry = new CapabilityRegistry;
    HostDefinition::read('weigh_ins_show')->description('List weigh-ins.')->cli('weigh-in', 'list')->input(TallyInput::class)->run(fn () => null)->register($registry);
    HostDefinition::write('weigh_in_log', affects: ['weigh_ins_show', 'weigh_in_show'])
        ->description('Log a weigh-in.')->cli('weigh-in', 'log')->input(TallyInput::class)
        ->run(fn () => new WriteResult(invalidates: []))->register($registry);
    HostDefinition::write('weigh_in_delete', affects: ['weigh_in_log'])
        ->description('Delete a weigh-in.')->cli('weigh-in', 'delete')->input(TallyInput::class)
        ->run(fn () => new WriteResult(invalidates: []))->register($registry);

    expect(HostDefinition::unknownAffects($registry))->toBe([
        'weigh_in_log affects weigh_in_show',
        'weigh_in_delete affects weigh_in_log',
    ]);
});

it('CAP-011 has every registered affects entry name a registered read', function () {
    // The one entry waiting for its read: settings_show is a contract read that #90 registers. Delete this line
    // when it does; until then nothing else may wait.
    $waiting = ['notification_prefs_set affects settings_show'];

    expect(array_values(array_diff(HostDefinition::unknownAffects(app(CapabilityRegistry::class)), $waiting)))->toBe([]);
});

it('CAP-011 has every registered write declare what it affects', function () {
    $silent = array_keys(array_filter(
        app(CapabilityRegistry::class)->all(),
        fn ($definition) => ! $definition->readOnly && HostDefinition::affects($definition) === [],
    ));

    expect($silent)->toBe([]);
});

it('CAP-013 marks a reversible write in its catalog entry', function () {
    $definition = HostDefinition::write('weigh_in_log', affects: ['weigh_ins_show'], reversible: true)->input(TallyInput::class)->toDefinition();

    expect(HostDefinition::isReversible($definition))->toBeTrue()
        ->and($definition->tags)->toBe(['affects:weigh_ins_show', 'reversible']);
});

it('CAP-013 leaves a write irreversible unless it says otherwise', function () {
    $definition = HostDefinition::write('weigh_in_log', affects: ['weigh_ins_show'])->input(TallyInput::class)->toDefinition();

    expect(HostDefinition::isReversible($definition))->toBeFalse();
});

it('CAP-011 lets a write affect any read, and does not call that an unknown read', function () {
    $registry = new CapabilityRegistry;
    HostDefinition::write('undo_apply', affects: [HostDefinition::ANY_READ])
        ->description('Undo a change.')->cli('undo', 'apply')->input(TallyInput::class)
        ->run(fn () => new WriteResult(invalidates: []))->register($registry);

    expect($registry->get('undo_apply')->tags)->toBe(['affects:*'])
        ->and(HostDefinition::unknownAffects($registry))->toBe([]);
});

it('CAP-011 names a write other than undo_apply that claims to affect any read', function () {
    $registry = new CapabilityRegistry;
    HostDefinition::write('weigh_in_log', affects: [HostDefinition::ANY_READ])
        ->description('Log a weigh-in.')->cli('weigh-in', 'log')->input(TallyInput::class)
        ->run(fn () => new WriteResult(invalidates: []))->register($registry);

    expect(HostDefinition::unknownAffects($registry))->toBe(['weigh_in_log affects *']);
});

it('AGT-010 marks a destructive write in its catalog entry', function () {
    $definition = HostDefinition::write('mesocycle_end', affects: ['train_show'], destructive: true)->input(TallyInput::class)->toDefinition();

    expect(HostDefinition::isDestructive($definition))->toBeTrue()
        ->and(HostDefinition::isReversible($definition))->toBeFalse()
        ->and($definition->tags)->toBe(['affects:train_show', 'destructive']);
});

it('AGT-010 leaves a write non-destructive unless it says otherwise', function () {
    $definition = HostDefinition::write('weigh_in_log', affects: ['weigh_ins_show'])->input(TallyInput::class)->toDefinition();

    expect(HostDefinition::isDestructive($definition))->toBeFalse()
        ->and(HostDefinition::isDestructive(HostDefinition::read('weigh_ins_show')->toDefinition()))->toBeFalse();
});
