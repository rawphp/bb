<?php

namespace App\Mcp;

use App\Agent\AnthropicToolSchema;
use App\Capabilities\Support\ErrorWording;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Rawphp\Capabilities\Adapters\Mcp\McpCredential;
use Rawphp\Capabilities\Adapters\Mcp\McpToolAdapter;

/**
 * One capability as a product MCP tool (AGT-040): its own name, description and
 * input schema, as the agent sees it (AGT-003). A call runs through the package's
 * MCP adapter as the token's user with caller `mcp`, inside the agent's profile,
 * so the CAP-020 authorizer and the token's scope decide it and Activity shows it.
 */
final class CapabilityTool extends Tool
{
    /** @var array<string, mixed> */
    private readonly array $inputSchema;

    /**
     * @param  array{name: string, description: string, input_schema: array<string, mixed>|null}  $capability  a row of CapabilityRegistry::mcpTools()
     * @param  list<string>  $profile  the agent's tool profile, which bounds every call
     */
    public function __construct(array $capability, private readonly array $profile)
    {
        $this->name = $capability['name'];
        $this->title = $capability['name'];
        $this->description = $capability['description'];
        $this->inputSchema = AnthropicToolSchema::normalise($capability['input_schema'] ?? []);
    }

    public function handle(Request $request, McpToolAdapter $adapter): Response
    {
        $user = $request->user();
        if ($user === null) {
            return Response::error('Sign in with a personal access token.');
        }

        $result = ErrorWording::apply($adapter->handle(
            $this->name(),
            $request->all(),
            McpCredential::userPat($user),
            ['profile' => $this->profile],
        ));

        return $result->isOk()
            ? Response::json($result->data)
            : Response::error((string) ($result->error['message'] ?? 'Something went wrong. Try again later.'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $tool = parent::toArray();
        $tool['inputSchema'] = $this->inputSchema;

        return $tool;
    }
}
