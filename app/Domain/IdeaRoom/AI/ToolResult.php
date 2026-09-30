<?php

declare(strict_types=1);

namespace Leantime\Domain\IdeaRoom\AI;

use InvalidArgumentException;

final class ToolResult
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $content,
        public readonly bool $isError = false,
    ) {
        if ($id === '' || strlen($id) > 256 || preg_match('/[\x00-\x1F\x7F]/', $id)
            || ! preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/', $name)
            || strlen($content) > 100000) {
            throw new InvalidArgumentException('Invalid tool result.');
        }
    }

    /** @return array{role: string, tool_call_id: string, name: string, content: string, is_error: bool} */
    public function asMessage(): array
    {
        return [
            'role' => 'tool',
            'tool_call_id' => $this->id,
            'name' => $this->name,
            'content' => $this->content,
            'is_error' => $this->isError,
        ];
    }
}
