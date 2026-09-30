<?php

declare(strict_types=1);

namespace Leantime\Domain\IdeaRoom\Mcp;

use InvalidArgumentException;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;
use Leantime\Core\Exceptions\AuthorizationException;
use Leantime\Core\Exceptions\NotFoundException;
use Leantime\Domain\IdeaRoom\Services\IdeaGraph;
use Leantime\Domain\IdeaRoom\Services\IdeaRoom;
use Leantime\Domain\IdeaRoom\Support\GraphConflictException;
use Throwable;

abstract class CanvasMutationTool extends Tool
{
    public function __construct(
        protected readonly IdeaGraph $graph,
        protected readonly IdeaRoom $rooms,
    ) {}

    protected function baseSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema->integer('roomId')->description('Accessible Idea Room ID.')->required()
            ->integer('expectedVersion')->description('Current canvas graph version; stale versions return a conflict.')->required();
    }

    public function toArray(): array
    {
        $tool = parent::toArray();
        $tool['inputSchema']['additionalProperties'] = false;

        return $tool;
    }

    /** @param array<string, mixed> $arguments
     * @param  list<string>  $fields
     * @param  callable(int,int):array{patch:array<string,mixed>,summary:string,cards?:list<array<string,mixed>>}  $build
     */
    protected function propose(array $arguments, array $fields, callable $build): ToolResult
    {
        try {
            $this->onlyFields($arguments, array_merge(['roomId', 'expectedVersion', 'expectedPlanVersion'], $fields));
            $roomId = self::positiveId($arguments['roomId'] ?? null, 'roomId');
            $version = self::version($arguments['expectedVersion'] ?? null);
            $change = $build($roomId, $version);
            $hasPlanChange = ($change['patch']['plan'] ?? []) !== [];
            $rawPlanVersion = $arguments['expectedPlanVersion'] ?? null;
            if ($hasPlanChange && (! is_int($rawPlanVersion) || $rawPlanVersion < 0)) {
                throw new InvalidArgumentException('Plan changes require expectedPlanVersion.');
            }
            if ($rawPlanVersion !== null && (! is_int($rawPlanVersion) || $rawPlanVersion < 0)) {
                throw new InvalidArgumentException('expectedPlanVersion must be a non-negative integer.');
            }
            $applied = $this->graph->applyPatch($roomId, $change['patch'], $change['cards'] ?? [], 'mcp', mb_substr($change['summary'], 0, 255), $version, $rawPlanVersion);
            $room = $this->rooms->room($roomId);

            $data = [
                'status' => 'applied',
                'historyEntry' => $applied['historyEntry'],
                'graph' => $applied['graph'],
                'plan' => $applied['plan'],
                'planVersion' => $applied['planVersion'],
                'currentRoomState' => [
                    'roomId' => $roomId,
                    'status' => $room['status'],
                    'mode' => $room['mode'],
                    'graphVersion' => (int) $room['graph_version'],
                    'planVersion' => (int) $room['plan_version'],
                ],
                'summary' => $change['summary'] ?: 'Canvas change applied. Restore an earlier history entry to undo it.',
            ];

            return StructuredIdeaRoomToolResult::success($data['summary'], $data);
        } catch (GraphConflictException) {
            return self::error('stale_version', 'The Idea Room changed. Reload it and retry with its current version.');
        } catch (AuthorizationException|NotFoundException) {
            return self::error('not_found_or_forbidden', 'The Idea Room or referenced item is unavailable.');
        } catch (InvalidArgumentException $error) {
            return self::error('invalid_request', $error->getMessage());
        } catch (Throwable) {
            return self::error('operation_failed', 'The canvas change could not be applied.');
        }
    }

    /** @param array<string, mixed> $arguments @param list<string> $allowed */
    protected function onlyFields(array $arguments, array $allowed): void
    {
        if (array_diff(array_keys($arguments), $allowed) !== []) {
            throw new InvalidArgumentException('Unknown canvas tool field.');
        }
    }

    protected static function positiveId(mixed $value, string $field): int
    {
        if (! is_int($value) || $value < 1) {
            throw new InvalidArgumentException($field.' must be a positive integer.');
        }

        return $value;
    }

    protected static function version(mixed $value): int
    {
        if (! is_int($value) || $value < 0) {
            throw new InvalidArgumentException('expectedVersion must be a non-negative integer.');
        }

        return $value;
    }

    /** @return array<string, mixed> */
    protected function visibleNode(int $roomId, int $nodeId): array
    {
        foreach ($this->graph->graph($roomId)['graph']['nodes'] as $node) {
            if ($node['id'] === $nodeId) {
                return $node;
            }
        }

        throw new InvalidArgumentException('The canvas node is not accessible.');
    }

    private static function error(string $code, string $message): ToolResult
    {
        return StructuredIdeaRoomToolResult::failure($code, $message);
    }
}
