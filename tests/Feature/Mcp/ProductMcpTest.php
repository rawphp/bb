<?php

use App\Agent\RegistryToolCatalog;
use App\Capabilities\Support\HostDefinition;
use App\Domain\Tokens\TokenScope;
use App\Models\AuditRecord;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Rawphp\Capabilities\Registry\CapabilityRegistry;
use Rawphp\CapabilitiesAi\Contracts\ToolCatalog;
use Tests\Support\Fixtures\Rail\TallyAddCapability;
use Tests\Support\Fixtures\Rail\TallyInput;

// Registers a test-only capability, so each test gets its own application.
uses()->group('warp-isolated');

beforeEach(function () {
    (new TallyAddCapability)->definition()->register(app(CapabilityRegistry::class));
    TallyAddCapability::$runs = 0;
    TallyAddCapability::$total = 0;
});

/** A personal access token's secret for the user (CAP-021). */
function mcpToken(User $user, TokenScope $scope = TokenScope::Everything, array $abilities = ['*']): string
{
    $secret = Str::random(40);
    $token = PersonalAccessToken::factory()->for($user, 'tokenable')
        ->create(['scope' => $scope, 'abilities' => $abilities, 'token' => hash('sha256', $secret)]);

    return $token->id.'|'.$secret;
}

/** One JSON-RPC request to the product MCP server. */
function mcpCall(?string $secret, string $method, array $params = []): TestResponse
{
    $request = $secret === null ? test() : test()->withHeader('Authorization', "Bearer {$secret}");

    return $request->postJson('/mcp/bodybuilder', ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params]);
}

/**
 * Every tool the MCP server lists, following its pages.
 *
 * @return list<array<string, mixed>>
 */
function mcpTools(string $secret): array
{
    $tools = [];
    $cursor = null;
    do {
        $result = mcpCall($secret, 'tools/list', $cursor === null ? [] : ['cursor' => $cursor])->assertOk()->json('result');
        $tools = [...$tools, ...$result['tools']];
        $cursor = $result['nextCursor'] ?? null;
    } while ($cursor !== null);

    return $tools;
}

it('AGT-040 lists over MCP, with a personal access token, the tools the in-app agent has', function () {
    $tools = mcpTools(mcpToken(User::factory()->create()));
    $agent = app(ToolCatalog::class)->toolsForTurn('CONV', 'TURN');

    expect(array_column($tools, 'name'))->toBe(array_column($agent, 'name'))
        ->and(array_column($tools, 'name'))->toContain('tally_add', 'account_show')
        ->and(array_column($tools, 'description'))->toBe(array_column($agent, 'description'))
        ->and(array_column($tools, 'inputSchema'))->toBe(json_decode(json_encode(array_column($agent, 'parameters')), true));
});

it('AGT-040 tells an MCP client which field of a tool call needs fixing', function () {
    $result = mcpCall(mcpToken(User::factory()->create()), 'tools/call', ['name' => 'tally_add', 'arguments' => ['amount' => 'three']])->assertOk()->json('result');

    expect($result['isError'])->toBeTrue()
        ->and($result['content'][0]['text'])->toStartWith('Some details need fixing.')->toContain("\namount: ")
        ->and(TallyAddCapability::$runs)->toBe(0);
});

it('AGT-040 serves the whole agent profile over MCP past the package\'s default tool caps, without a boundary audit row', function () {
    $registry = app(CapabilityRegistry::class);
    foreach (range(1, 70) as $n) {
        HostDefinition::read("probe{$n}_show")->description("Show probe {$n}. It changes nothing.")->cli('probe', "show{$n}")
            ->input(TallyInput::class)->run(fn () => null)->register($registry);
    }

    $tools = mcpTools(mcpToken(User::factory()->create()));

    expect(count($tools))->toBe(count(app(RegistryToolCatalog::class)->names()))->toBeGreaterThan(64)
        ->and(AuditRecord::query()->count())->toBe(0);
});

it('AGT-040 keeps the MCP tool caps above the agent profile, so MCP never refuses part of it', function () {
    $profile = count(app(RegistryToolCatalog::class)->names());

    expect(config('capabilities.surfaces.mcp.max_tools_warn'))->toBeGreaterThanOrEqual($profile)
        ->and(config('capabilities.surfaces.mcp.max_tools_hard'))->toBeGreaterThanOrEqual(config('capabilities.surfaces.mcp.max_tools_warn'));
});

it('AGT-040 answers MCP without a personal access token with 401', function () {
    mcpCall(null, 'tools/list')->assertUnauthorized();
    mcpCall('1|not-a-token', 'tools/list')->assertUnauthorized();
});

it('AGT-040 runs an MCP tool call as the token\'s user, and Activity records it as mcp', function () {
    mcpToken(User::factory()->create());
    $user = User::factory()->create();

    $result = mcpCall(mcpToken($user), 'tools/call', ['name' => 'tally_add', 'arguments' => ['amount' => 3]])->assertOk()->json('result');

    expect($result['isError'])->toBeFalse()
        ->and(TallyAddCapability::$total)->toBe(3)
        ->and(AuditRecord::sole())->user_id->toBe($user->id)->channel->toBe('mcp');
});

it('AGT-040 holds an MCP tool call to the token\'s scope (CAP-023), in plain words', function () {
    $secret = mcpToken(User::factory()->create(), TokenScope::Custom, ['account_show']);

    $result = mcpCall($secret, 'tools/call', ['name' => 'tally_add', 'arguments' => ['amount' => 3]])->assertOk()->json('result');

    expect($result['isError'])->toBeTrue()
        ->and($result['content'][0]['text'])->toBe('You can\'t do that.')
        ->and(TallyAddCapability::$runs)->toBe(0);
});

it('AGT-040 neither offers nor runs a CAP-031 exclusion over MCP', function () {
    $secret = mcpToken(User::factory()->create());

    expect(array_column(mcpTools($secret), 'name'))->not->toContain('token_create', 'token_revoke', 'account_delete');
    mcpCall($secret, 'tools/call', ['name' => 'token_create', 'arguments' => []])->assertJsonPath('error.code', -32602);
    expect(PersonalAccessToken::query()->count())->toBe(1);
});

it('AGT-040 offers and runs no tools over MCP when CAPABILITIES_SURFACE_MCP is off', function () {
    $registry = app(CapabilityRegistry::class);
    $registry->withGloballyEnabledSurfaces(['mcp' => false] + $registry->globallyEnabledSurfaces());
    $secret = mcpToken(User::factory()->create());

    expect(mcpTools($secret))->toBe([]);
    mcpCall($secret, 'tools/call', ['name' => 'tally_add', 'arguments' => ['amount' => 3]])->assertJsonPath('error.code', -32602);
    expect(TallyAddCapability::$runs)->toBe(0);
});

it('AGT-040 capabilities:mcp-status reports the MCP endpoint, its auth and its tools', function () {
    $exit = Artisan::call('capabilities:mcp-status');
    $output = Artisan::output();
    $names = array_column(app(ToolCatalog::class)->toolsForTurn('CONV', 'TURN'), 'name');

    expect($exit)->toBe(0)
        ->and($output)->toContain('MCP: on')
        ->and($output)->toContain(url('/mcp/bodybuilder'))
        ->and($output)->toContain('personal access token')
        ->and($output)->toContain('Tools: '.count($names));
    foreach ($names as $name) {
        expect($output)->toContain($name);
    }
});

it('AGT-040 capabilities:mcp-status says when the MCP surface is off', function () {
    $registry = app(CapabilityRegistry::class);
    $registry->withGloballyEnabledSurfaces(['mcp' => false] + $registry->globallyEnabledSurfaces());

    $exit = Artisan::call('capabilities:mcp-status');

    expect($exit)->toBe(0)
        ->and(Artisan::output())->toContain('MCP: off')->toContain('CAPABILITIES_SURFACE_MCP')->toContain('Tools: 0');
});
