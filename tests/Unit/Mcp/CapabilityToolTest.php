<?php

use App\Mcp\CapabilityTool;
use App\Models\User;
use Laravel\Mcp\Request;
use Rawphp\Capabilities\Adapters\Mcp\McpCredential;
use Rawphp\Capabilities\Adapters\Mcp\McpToolAdapter;
use Rawphp\Capabilities\Support\CapabilityResult;

function showTool(): CapabilityTool
{
    return new CapabilityTool(
        ['name' => 'account_show', 'description' => 'Show the account. It changes nothing.', 'input_schema' => ['type' => 'object', 'properties' => []]],
        profile: ['account_show', 'weigh_ins_show'],
    );
}

it('AGT-040 offers a capability as an MCP tool under its own name, description and input schema', function () {
    $tool = showTool()->toArray();

    expect($tool['name'])->toBe('account_show')
        ->and($tool['description'])->toBe('Show the account. It changes nothing.')
        ->and(json_encode($tool['inputSchema']))->toBe('{"type":"object","properties":{}}');
});

it('AGT-040 runs a tool call as the signed-in user with caller mcp, inside the agent\'s profile', function () {
    $user = User::factory()->make(['id' => 7]);
    $this->actingAs($user);
    $adapter = Mockery::mock(McpToolAdapter::class);
    $adapter->shouldReceive('handle')->once()
        ->withArgs(fn (string $name, array $input, McpCredential $credential, array $options): bool => $name === 'account_show'
            && $input === ['days' => 3]
            && $credential->authProfile === 'user_pat'
            && $credential->user === $user
            && $options === ['profile' => ['account_show', 'weigh_ins_show']])
        ->andReturn(CapabilityResult::ok(['name' => 'Sam Lifter']));

    $response = showTool()->handle(new Request(['days' => 3]), $adapter);

    expect($response->isError())->toBeFalse()
        ->and(json_decode((string) $response->content(), true))->toBe(['name' => 'Sam Lifter']);
});

it('AGT-040 answers a refused tool call as a tool error in plain words (CAP-012)', function () {
    $this->actingAs(User::factory()->make(['id' => 7]));
    $adapter = Mockery::mock(McpToolAdapter::class);
    $adapter->shouldReceive('handle')->andReturn(CapabilityResult::failure('forbidden', 'Not authorized to invoke "account_show".'));

    $response = showTool()->handle(new Request, $adapter);

    expect($response->isError())->toBeTrue()
        ->and((string) $response->content())->toBe('You can\'t do that.');
});

it('AGT-040 refuses a tool call with no signed-in user, without calling the capability', function () {
    $adapter = Mockery::mock(McpToolAdapter::class);
    $adapter->shouldNotReceive('handle');

    $response = showTool()->handle(new Request, $adapter);

    expect($response->isError())->toBeTrue();
});

it('AGT-040 names each field that needs fixing in a tool error, as the agent gets them', function () {
    $this->actingAs(User::factory()->make(['id' => 7]));
    $adapter = Mockery::mock(McpToolAdapter::class);
    $adapter->shouldReceive('handle')->andReturn(CapabilityResult::failure('validation_failed', 'JSON Schema validation failed.', [
        'violations' => [['field' => 'amount', 'message' => 'Enter a whole number.'], ['field' => 'note', 'message' => 'Keep it under 200 characters.']],
    ]));

    $response = showTool()->handle(new Request, $adapter);

    expect($response->isError())->toBeTrue()
        ->and((string) $response->content())->toBe("Some details need fixing.\namount: Enter a whole number.\nnote: Keep it under 200 characters.");
});
