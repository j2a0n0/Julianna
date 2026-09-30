<?php

declare(strict_types=1);

namespace Leantime\Domain\IdeaRoom\AI;

use InvalidArgumentException;

final class ToolCall
{
    /** @param array<string, mixed> $arguments */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly array $arguments,
    ) {
        if ($id === '' || strlen($id) > 256 || preg_match('/[\x00-\x1F\x7F]/', $id)
            || ! preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/', $name)
            || (array_is_list($arguments) && $arguments !== [])) {
            throw new InvalidArgumentException('Invalid tool call.');
        }
    }

    /** @return array{id: string, name: string, arguments: array<string, mixed>} */
    public function toArray(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'arguments' => $this->arguments];
    }

    /** @param array<string, mixed> $value */
    public static function fromArray(array $value): self
    {
        if (! is_string($value['id'] ?? null)
            || ! is_string($value['name'] ?? null)
            || ! is_array($value['arguments'] ?? null)) {
            throw new InvalidArgumentException('Invalid tool call.');
        }

        return new self($value['id'], $value['name'], $value['arguments']);
    }
}
