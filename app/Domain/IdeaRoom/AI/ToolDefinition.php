<?php

declare(strict_types=1);

namespace Leantime\Domain\IdeaRoom\AI;

use InvalidArgumentException;

final class ToolDefinition
{
    /** @param array<string, mixed> $parameters */
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly array $parameters,
        public readonly bool $readOnly = true,
        public readonly bool $destructive = false,
    ) {
        if (! preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/', $name)
            || trim($description) === ''
            || ($parameters['type'] ?? null) !== 'object') {
            throw new InvalidArgumentException('Invalid tool definition.');
        }
    }

    /** @return array<string, mixed> */
    public function openAiSchema(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $this->name,
                'description' => $this->description,
                'parameters' => $this->parameters,
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function anthropicSchema(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
            'input_schema' => $this->parameters,
        ];
    }
}
