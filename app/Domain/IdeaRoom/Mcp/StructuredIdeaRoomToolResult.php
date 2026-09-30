<?php

declare(strict_types=1);

namespace Leantime\Domain\IdeaRoom\Mcp;

use Laravel\Mcp\Server\Tools\ToolResult;

/**
 * Laravel MCP 0.1.1 does not yet put structuredContent on ToolResult. The server
 * serializes toArray(), so this subtype adds it without changing the dependency.
 */
final class StructuredIdeaRoomToolResult extends ToolResult
{
    private function __construct(
        private readonly string $summary,
        private readonly array $data,
        private readonly bool $failed,
    ) {}

    /** @param array<string, mixed> $data */
    public static function success(string $summary, array $data): ToolResult
    {
        return new self($summary, $data, false);
    }

    /** @param array<string, mixed> $details */
    public static function failure(string $code, string $message, array $details = []): ToolResult
    {
        return new self($message, ['code' => $code, 'message' => $message] + $details, true);
    }

    public function toArray(): array
    {
        return [
            'content' => [['type' => 'text', 'text' => $this->summary]],
            'structuredContent' => $this->data,
            'isError' => $this->failed,
        ];
    }
}
