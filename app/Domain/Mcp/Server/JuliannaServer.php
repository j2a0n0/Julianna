<?php

declare(strict_types=1);

namespace Leantime\Domain\Mcp\Server;

use Laravel\Mcp\Server;
use Leantime\Domain\Mcp\Services\ToolDispatcher;

/** First-party, token-authenticated MCP transport for Julianna's shared catalog. */
final class JuliannaServer extends Server
{
    public array $supportedProtocolVersion = ['2025-11-25', '2025-06-18', '2025-03-26', '2024-11-05'];

    public string $serverName = 'Julianna';

    public string $serverVersion = '1.0.0-dev';

    public string $instructions = 'Use project-scoped tools only for projects you may access. Whiteboard edits require the current expected revision. Communication may be drafted for review in the application.';

    public int $maxPaginationLength = 100;

    public int $defaultPaginationLength = 100;

    public function boot(): void
    {
        $this->serverVersion = trim((string) config('app.version', '1.0.0-dev')) ?: '1.0.0-dev';
        $dispatcher = app(ToolDispatcher::class);
        foreach ($dispatcher->advertisedMcpTools() as $definition) {
            $this->addTool(new CatalogMcpTool($definition, $dispatcher));
        }
    }
}
