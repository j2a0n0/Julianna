<?php

declare(strict_types=1);

namespace Leantime\Domain\IdeaRoom\Mcp\Tools;

use InvalidArgumentException;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\ToolResult;
use Leantime\Core\Exceptions\AuthorizationException;
use Leantime\Core\Exceptions\NotFoundException;
use Leantime\Domain\IdeaRoom\Mcp\StructuredIdeaRoomToolResult;
use Leantime\Domain\IdeaRoom\Support\GraphConflictException;
use Throwable;

/** Common, non-disclosing error boundary for agent-initiated Idea Room changes. */
abstract class IdeaRoomMutationTool extends Tool
{
    /** @param list<string> $required @param list<string> $optional */
    protected function checkArguments(array $arguments, array $required, array $optional = []): void
    {
        if (array_diff(array_keys($arguments), [...$required, ...$optional]) !== []) {
            throw new InvalidArgumentException('Unknown tool argument.');
        }
        foreach ($required as $key) {
            if (! array_key_exists($key, $arguments)) {
                throw new InvalidArgumentException('Missing required argument: '.$key.'.');
            }
        }
    }

    protected function positiveId(array $arguments, string $key): int
    {
        $value = $arguments[$key] ?? null;
        if (! is_int($value) || $value < 1) {
            throw new InvalidArgumentException('Provide a valid '.$key.'.');
        }

        return $value;
    }

    protected function version(array $arguments, string $key = 'expectedVersion'): int
    {
        $value = $arguments[$key] ?? null;
        if (! is_int($value) || $value < 0) {
            throw new InvalidArgumentException('Provide a valid '.$key.'.');
        }

        return $value;
    }

    protected function stringValue(array $arguments, string $key, int $maxLength, bool $allowEmpty = false): string
    {
        $value = $arguments[$key] ?? null;
        if (! is_string($value) || mb_strlen($value) > $maxLength || (! $allowEmpty && trim($value) === '')) {
            throw new InvalidArgumentException('Provide a valid '.$key.'.');
        }

        return trim($value);
    }

    /** @param callable(): ToolResult $operation */
    protected function safely(callable $operation): ToolResult
    {
        try {
            return $operation();
        } catch (GraphConflictException) {
            return StructuredIdeaRoomToolResult::failure('stale_version', 'The room changed. Reload its current state and retry.');
        } catch (AuthorizationException|NotFoundException) {
            return StructuredIdeaRoomToolResult::failure('not_found_or_forbidden', 'The Idea Room or referenced item is unavailable.');
        } catch (InvalidArgumentException $exception) {
            return StructuredIdeaRoomToolResult::failure('invalid_request', $exception->getMessage());
        } catch (Throwable) {
            // Never leak provider credentials, transcript content, or stack traces through MCP.
            return StructuredIdeaRoomToolResult::failure('operation_failed', 'The Idea Room operation could not be completed.');
        }
    }

    /** The MCP package's schema builder does not expose root additionalProperties. */
    public function toArray(): array
    {
        $data = parent::toArray();
        $data['inputSchema']['additionalProperties'] = false;

        return $data;
    }
}
