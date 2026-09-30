<?php

declare(strict_types=1);

namespace Unit\app\Domain\IdeaRoom\Mcp;

use Laravel\Mcp\Server;
use Leantime\Domain\IdeaRoom\Mcp\IdeaRoomMcpToolProvider;
use Leantime\Domain\IdeaRoom\Mcp\StructuredIdeaRoomToolResult;
use PHPUnit\Framework\TestCase;

final class IdeaRoomMcpToolProviderTest extends TestCase
{
    public function test_retired_idea_room_provider_exposes_no_tools(): void
    {
        self::assertSame([], IdeaRoomMcpToolProvider::toolClasses());
    }

    public function test_result_contains_structured_json_and_concise_text(): void
    {
        $result = StructuredIdeaRoomToolResult::success('Two rooms.', ['rooms' => [['id' => 1], ['id' => 2]]])->toArray();
        self::assertSame('Two rooms.', $result['content'][0]['text']);
        self::assertSame(['rooms' => [['id' => 1], ['id' => 2]]], $result['structuredContent']);
        self::assertFalse($result['isError']);

        $error = StructuredIdeaRoomToolResult::failure('room_not_found', 'Unavailable.')->toArray();
        self::assertTrue($error['isError']);
        self::assertSame('room_not_found', $error['structuredContent']['code']);
    }

    public function test_provider_registers_catalog_on_existing_server_instance(): void
    {
        $server = new class extends Server
        {
            public function registeredToolClasses(): array
            {
                return $this->registeredTools;
            }
        };

        IdeaRoomMcpToolProvider::register($server);
        self::assertSame([], $server->registeredToolClasses());
    }
}
