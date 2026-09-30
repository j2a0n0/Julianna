<?php

use Laravel\Mcp\Server\Facades\Mcp;
use Leantime\Domain\Mcp\Server\JuliannaServer;

// Global AuthCheck requires a personal bearer token or an active Julianna API key.
// Neither a browser cookie nor a queued run is accepted as MCP transport authority.
Mcp::web('/mcp', JuliannaServer::class);
