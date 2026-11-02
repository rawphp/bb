<?php

use App\Mcp\BodybuilderServer;
use App\Mcp\ProductMcp;
use Laravel\Mcp\Facades\Mcp;

// Product MCP (AGT-040). laravel/mcp loads this file. A personal access token is the
// only way in: no session middleware runs here, so auth:sanctum reads the bearer.
Mcp::web(ProductMcp::PATH, BodybuilderServer::class)->middleware('auth:sanctum');
