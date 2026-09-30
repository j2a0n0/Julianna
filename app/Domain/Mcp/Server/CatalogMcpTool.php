<?php

declare(strict_types=1);

namespace Leantime\Domain\Mcp\Server;

use InvalidArgumentException;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;
use Leantime\Core\Exceptions\AuthorizationException;
use Leantime\Domain\Mcp\Services\ToolDispatcher;
use Throwable;

/** Protocol adapter; the dispatcher owns schema validation and authorization. */
final class CatalogMcpTool extends Tool
{
    /** @param array<string,mixed> $definition */
    public function __construct(
        private readonly array $definition,
        private readonly ToolDispatcher $dispatcher,
    ) {}

    public function name(): string
    {
        return $this->definition['name'];
    }

    public function description(): string
    {
        return $this->definition['description'];
    }

    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        foreach ($this->definition['input_schema']['properties'] as $key => $property) {
            $schema->raw($key, $property);
            if (in_array($key, $this->definition['input_schema']['required'] ?? [], true)) {
                $schema->required();
            }
        }

        return $schema;
    }

    public function toArray(): array
    {
        return [
            'name' => $this->name(),
            'description' => $this->description(),
            'inputSchema' => $this->definition['input_schema'],
            'annotations' => [
                'readOnlyHint' => $this->definition['effect'] === 'read',
                'destructiveHint' => false,
                'idempotentHint' => $this->definition['effect'] === 'read',
                'openWorldHint' => false,
            ],
        ];
    }

    public function handle(array $arguments): ToolResult
    {
        try {
            $actorId = (int) session('userdata.id');
            $result = $this->dispatcher->dispatch($this->name(), $arguments, $actorId, source: 'mcp');

            return $result['ok'] ? ToolResult::text($result['text']) : ToolResult::error($result['text']);
        } catch (AuthorizationException) {
            return ToolResult::error('Tool unavailable for this account or project.');
        } catch (InvalidArgumentException $error) {
            return ToolResult::error($error->getMessage());
        } catch (Throwable) {
            // In particular, never log transcript content or provider credentials.
            return ToolResult::error('Tool action failed.');
        }
    }
}
