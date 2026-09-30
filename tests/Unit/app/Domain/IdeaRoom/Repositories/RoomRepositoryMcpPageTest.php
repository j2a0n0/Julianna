<?php

declare(strict_types=1);

namespace Unit\app\Domain\IdeaRoom\Repositories;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\SQLiteConnection;
use Leantime\Domain\IdeaRoom\Repositories\RoomRepository;
use PDO;
use PHPUnit\Framework\TestCase;

final class RoomRepositoryMcpPageTest extends TestCase
{
    private SQLiteConnection $connection;

    private RoomRepository $rooms;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection = new SQLiteConnection(new PDO('sqlite::memory:'));
        $schema = $this->connection->getSchemaBuilder();
        $schema->create('julianna_idea_rooms', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('owner_user_id');
            $table->unsignedBigInteger('project_id')->nullable();
            $table->string('title');
            $table->string('status');
            $table->json('plan_json');
            $table->json('approval_result_json')->nullable();
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
        });
        $schema->create('julianna_idea_messages', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('room_id');
            $table->string('role');
            $table->longText('content');
            $table->json('metadata_json')->nullable();
            $table->dateTime('created_at');
        });
        $this->rooms = new RoomRepository($this->connection);
    }

    public function test_room_candidates_are_scoped_and_paginated_for_owner_project_and_admin(): void
    {
        foreach ([
            [1, 1, null], [2, 2, 9], [3, 2, 8], [4, 2, null], [5, 1, 8], [6, 2, 9],
        ] as [$id, $owner, $project]) {
            $this->connection->table('julianna_idea_rooms')->insert([
                'id' => $id, 'owner_user_id' => $owner, 'project_id' => $project,
                'title' => 'Room '.$id, 'status' => 'active', 'plan_json' => '{}',
                'created_at' => '2026-09-22 00:00:00', 'updated_at' => '2026-09-22 00:00:00',
            ]);
        }

        $first = $this->rooms->listAccessibleCandidatesPage(1, [9], false, 0, 2);
        self::assertSame([6, 5], array_column($first, 'id'));
        $second = $this->rooms->listAccessibleCandidatesPage(1, [9], false, 5, 2);
        self::assertSame([2, 1], array_column($second, 'id'));
        self::assertSame([6, 5, 4, 3, 2, 1], array_column(
            $this->rooms->listAccessibleCandidatesPage(1, [], true, 0, 10), 'id'
        ));
    }

    public function test_message_page_excludes_tool_messages_and_internal_metadata(): void
    {
        $this->connection->table('julianna_idea_messages')->insert([
            ['id' => 1, 'room_id' => 7, 'role' => 'user', 'content' => 'Hello', 'metadata_json' => null, 'created_at' => '2026-09-22 00:00:00'],
            ['id' => 2, 'room_id' => 7, 'role' => 'tool', 'content' => 'Hidden', 'metadata_json' => '{"api_key":"secret"}', 'created_at' => '2026-09-22 00:00:01'],
            ['id' => 3, 'room_id' => 7, 'role' => 'assistant', 'content' => 'Hi', 'metadata_json' => '{"reasoning_content":"private"}', 'created_at' => '2026-09-22 00:00:02'],
        ]);

        $first = $this->rooms->messagesPage(7, 0, 1);
        $second = $this->rooms->messagesPage(7, 1, 2);
        self::assertSame([1], array_column($first, 'id'));
        self::assertSame([3], array_column($second, 'id'));
        self::assertSame(['id', 'role', 'content', 'createdAt'], array_keys($second[0]));
        self::assertSame('Hi', $second[0]['content']);
    }
}
