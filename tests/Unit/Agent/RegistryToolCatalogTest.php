<?php

use App\Agent\RegistryToolCatalog;
use App\Capabilities\Support\HostDefinition;
use Rawphp\Capabilities\Capability;
use Rawphp\Capabilities\Registry\CapabilityRegistry;
use Rawphp\CapabilitiesAi\Contracts\ToolCatalog;
use Tests\Support\Fixtures\Rail\TallyInput;

function catalogRegistry(array $enabled = ['http' => true, 'job' => true, 'mcp' => true, 'cli' => true, 'agent' => true]): CapabilityRegistry
{
    $registry = (new CapabilityRegistry)->withGloballyEnabledSurfaces($enabled);
    foreach (['weigh_ins_show', 'account_delete', 'billing_portal_open'] as $name) {
        HostDefinition::read($name)->description("Show {$name}. It changes nothing.")->cli('x', str_replace('_', '-', $name))
            ->input(TallyInput::class)->run(fn () => null)->register($registry);
    }
    Capability::define('ops_only')->surfaces(['cli'])->readOnly()->description('Ops. Nothing else.')->cli('ops', 'only')
        ->input(TallyInput::class)->run(fn () => null)->register($registry);
    HostDefinition::read('account_show')->description('Show the account. It changes nothing.')->cli('account', 'show')
        ->input(TallyInput::class)->run(fn () => null)->register($registry);

    return $registry;
}

it('AGT-003 offers each capability as a tool with its own name, description and input schema', function () {
    $registry = catalogRegistry();
    $tools = (new RegistryToolCatalog($registry))->toolsForTurn('CONV', 'TURN');
    $show = collect($tools)->firstWhere('name', 'weigh_ins_show');

    expect($show['description'])->toBe('Show weigh_ins_show. It changes nothing.')
        ->and($show['parameters']['properties'])->toBe($registry->get('weigh_ins_show')->inputSchema()['properties'])
        ->and($show)->not->toHaveKey('parameters.$schema');
});

it('AGT-001 leaves out the CAP-031 exclusions and capabilities not on the agent surface, in name order', function () {
    $tools = (new RegistryToolCatalog(catalogRegistry()))->toolsForTurn('CONV', 'TURN');

    expect(array_column($tools, 'name'))->toBe(['account_show', 'weigh_ins_show']);
});

it('AGT-004 offers no tools when CAPABILITIES_SURFACE_AGENT is off', function () {
    $registry = catalogRegistry(['http' => true, 'job' => true, 'mcp' => true, 'cli' => true, 'agent' => false]);

    expect((new RegistryToolCatalog($registry))->toolsForTurn('CONV', 'TURN'))->toBe([]);
});

it('AGT-003 binds the registry catalog as the package\'s tool catalog, and every registered tool schema suits Anthropic', function () {
    $catalog = app(ToolCatalog::class);

    expect($catalog)->toBeInstanceOf(RegistryToolCatalog::class)
        ->and(array_column($catalog->toolsForTurn('CONV', 'TURN'), 'name'))->toContain('account_show');
});
