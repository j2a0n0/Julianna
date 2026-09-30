<?php

declare(strict_types=1);

namespace Leantime\Domain\IdeaRoom\Mcp\Tools;

use InvalidArgumentException;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;
use Leantime\Core\Exceptions\AuthorizationException;
use Leantime\Core\Exceptions\NotFoundException;
use Leantime\Domain\IdeaRoom\Mcp\StructuredIdeaRoomToolResult;
use Throwable;

abstract class AbstractIdeaRoomReadTool extends Tool
{
    protected const DEFAULT_PAGE_SIZE = 20;

    protected const MAX_PAGE_SIZE = 50;

    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema->integer('roomId')->description('Positive ID of an Idea Room you can access.')->required();
    }

    /** @return list<string> */
    protected function allowedArguments(): array
    {
        return ['roomId'];
    }

    /** @return array{summary: string, data: array<string, mixed>} */
    abstract protected function read(array $arguments): array;

    public function handle(array $arguments): ToolResult
    {
        try {
            $unknown = array_diff(array_keys($arguments), $this->allowedArguments());
            if ($unknown !== []) {
                throw new InvalidArgumentException('Unknown input field: '.implode(', ', $unknown));
            }
            $result = $this->read($arguments);

            return StructuredIdeaRoomToolResult::success($result['summary'], $result['data']);
        } catch (AuthorizationException|NotFoundException) {
            // Do not distinguish an inaccessible room from a missing room.
            return StructuredIdeaRoomToolResult::failure('room_not_found', 'Idea Room not found or inaccessible.');
        } catch (InvalidArgumentException $error) {
            return StructuredIdeaRoomToolResult::failure('invalid_arguments', $error->getMessage());
        } catch (Throwable) {
            return StructuredIdeaRoomToolResult::failure('read_failed', 'The Idea Room could not be read.');
        }
    }

    public function toArray(): array
    {
        $data = parent::toArray();
        $data['inputSchema']['additionalProperties'] = false;

        return $data;
    }

    protected static function roomId(array $arguments): int
    {
        return self::positiveInt($arguments, 'roomId');
    }

    protected static function positiveInt(array $arguments, string $field): int
    {
        $value = $arguments[$field] ?? null;
        if (! is_int($value) || $value < 1) {
            throw new InvalidArgumentException($field.' must be a positive integer.');
        }

        return $value;
    }

    protected static function cursor(array $arguments, string $field): int
    {
        $value = $arguments[$field] ?? 0;
        if (! is_int($value) || $value < 0) {
            throw new InvalidArgumentException($field.' must be a non-negative integer.');
        }

        return $value;
    }

    protected static function pageSize(array $arguments): int
    {
        $value = $arguments['limit'] ?? self::DEFAULT_PAGE_SIZE;
        if (! is_int($value) || $value < 1 || $value > self::MAX_PAGE_SIZE) {
            throw new InvalidArgumentException('limit must be an integer from 1 to '.self::MAX_PAGE_SIZE.'.');
        }

        return $value;
    }

    /** @param array<string, mixed> $room
     * @return array<string, mixed>
     */
    protected static function roomSummary(array $room): array
    {
        return [
            'id' => (int) $room['id'],
            'title' => (string) $room['title'],
            'status' => (string) $room['status'],
            'mode' => (string) ($room['mode'] ?? 'explore'),
            'projectId' => $room['project_id'] === null ? null : (int) $room['project_id'],
            'projectName' => (string) ($room['project_name'] ?? ''),
            'graphVersion' => (int) ($room['graph_version'] ?? 0),
            'createdAt' => (string) ($room['created_at'] ?? ''),
            'updatedAt' => (string) ($room['updated_at'] ?? ''),
        ];
    }
}
