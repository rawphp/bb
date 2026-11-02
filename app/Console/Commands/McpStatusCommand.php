<?php

namespace App\Console\Commands;

use App\Mcp\ProductMcp;
use Illuminate\Console\Command;

/** Reports the product MCP server (AGT-040): on or off, where, how to sign in, and its tools. */
final class McpStatusCommand extends Command
{
    protected $signature = 'capabilities:mcp-status';

    protected $description = 'Report the product MCP server: on or off, its URL, its auth and its tools';

    public function handle(ProductMcp $mcp): int
    {
        $tools = $mcp->tools();

        $this->line($mcp->enabled() ? 'MCP: on' : 'MCP: off (CAPABILITIES_SURFACE_MCP=false)');
        $this->line('URL: '.url(ProductMcp::PATH));
        $this->line('Auth: a personal access token, sent as Authorization: Bearer <token>');
        $this->line('Tools: '.count($tools).', the in-app agent\'s profile');
        foreach ($tools as $tool) {
            $this->line('  '.$tool->name());
        }

        if ($mcp->enabled() && $tools === []) {
            $this->warn('MCP is on but serves no tools.');
        }

        return self::SUCCESS;
    }
}
