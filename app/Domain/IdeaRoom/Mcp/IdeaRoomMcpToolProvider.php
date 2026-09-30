<?php

declare(strict_types=1);

namespace Leantime\Domain\IdeaRoom\Mcp;

use Laravel\Mcp\Server;
/**
 * Compatibility shim for deployments that still load the former private MCP
 * plugin. Idea Room is a read-only archive; none of its old tools are exposed.
 * The active first-party catalog lives in the Julianna MCP domain instead.
 */
final class IdeaRoomMcpToolProvider
{
    /** @return list<class-string<\Laravel\Mcp\Server\Tool>> */
    public static function toolClasses(): array
    {
        return [];
    }

    public static function register(Server $server): void
    {
        // Intentionally no-op. Archived proposals are never execution authority.
    }
}
