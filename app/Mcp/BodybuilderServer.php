<?php

namespace App\Mcp;

use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Contracts\Transport;

/** The product MCP server at /mcp/bodybuilder (AGT-040). routes/ai.php mounts it. */
final class BodybuilderServer extends Server
{
    protected string $name = 'Bodybuilder';

    protected string $version = '1.0.0';

    protected string $instructions = <<<'MARKDOWN'
        Run your Bodybuilder training and nutrition. Each tool is one thing the app can do,
        done as you: the same tools the in-app Coach has. Read before you write, and log
        only what you were told.
    MARKDOWN;

    public function __construct(Transport $transport, private readonly ProductMcp $mcp)
    {
        parent::__construct($transport);
    }

    protected function boot(): void
    {
        $this->tools = $this->mcp->tools();
    }
}
