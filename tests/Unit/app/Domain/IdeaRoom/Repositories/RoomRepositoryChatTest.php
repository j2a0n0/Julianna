<?php

declare(strict_types=1);

namespace Unit\app\Domain\IdeaRoom\Repositories;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\SQLiteConnection;
use Leantime\Domain\IdeaRoom\Repositories\RoomRepository;
use PDO;
use PHPUnit\Framework\TestCase;

final class RoomRepositoryChatTest extends TestCase
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
        });
        $schema->create('julianna_idea_messages', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('room_id');
            $table->string('role');
            $table->longText('content');
            $table->json('metadata_json')->nullable();
            $table->dateTime('created_at');
        });
        $schema->create('julianna_idea_events', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('room_id');
            $table->string('event_name');
            $table->json('payload_json');
            $table->dateTime('created_at');
        });
        $schema->create('julianna_idea_actions', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('room_id');
            $table->string('tool_call_id');
            $table->string('tool_name');
            $table->json('arguments_json');
            $table->string('status');
            $table->boolean('destructive');
            $table->string('idempotency_key')->unique();
            $table->json('result_json')->nullable();
            $table->dateTime('expires_at');
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
        });
        $schema->create('julianna_idea_generations', static function (Blueprint $table): void {
            $table->unsignedBigInteger('room_id')->primary();
            $table->string('status');
            $table->boolean('cancel_requested');
            $table->unsignedTinyInteger('tool_turns');
            $table->dateTime('updated_at');
        });
        $this->connection->table('julianna_idea_rooms')->insert(['id' => 1]);
        $this->rooms = new RoomRepository($this->connection);
    }

    public function test_transcript_metadata_and_event_replay_survive_reload(): void
    {
        $this->rooms->addMessage(1, 'user', 'Find my tasks');
        $this->rooms->addMessage(1, 'assistant', 'Searching.', [
            'tool_calls' => [['id' => 'call-1', 'name' => 'findTasks', 'arguments' => ['projectIds' => [2]]]],
        ]);
        $this->rooms->addMessage(1, 'tool', 'One task found.', ['tool_call_id' => 'call-1', 'name' => 'findTasks']);
        $first = $this->rooms->addEvent(1, 'message.started', ['role' => 'assistant']);
        $second = $this->rooms->addEvent(1, 'tool.result', ['text' => 'One task found.']);

        self::assertCount(3, $this->rooms->messages(1));
        self::assertSame('findTasks', $this->rooms->messages(1)[1]['metadata']['tool_calls'][0]['name']);
        self::assertSame([$second], $this->rooms->events(1, (int) $first['id']));
        self::assertSame([$first, $second], $this->rooms->recentEvents(1));
    }

    public function test_confirmation_key_is_private_and_action_transitions_are_persistent(): void
    {
        $action = $this->rooms->addAction(1, 'call-2', 'deleteEvent', ['id' => 9], true);
        self::assertSame('pending', $action['status']);
        self::assertTrue((bool) $action['destructive']);
        self::assertArrayNotHasKey('idempotency_key', $action);
        self::assertCount(1, $this->rooms->pendingActions(1));

        $this->rooms->updateAction(1, (int) $action['id'], 'rejected', ['ok' => false, 'text' => 'Rejected']);
        self::assertSame([], $this->rooms->pendingActions(1));
        self::assertSame('rejected', $this->rooms->action(1, (int) $action['id'])['status']);
        self::assertSame('Rejected', $this->rooms->action(1, (int) $action['id'])['result']['text']);
    }

    public function test_generation_state_can_be_resumed_and_cancelled(): void
    {
        $this->rooms->setGeneration(1, 'running', resetTurns: true);
        $this->rooms->incrementToolTurns(1);
        self::assertSame(1, (int) $this->rooms->generation(1)['tool_turns']);

        $this->rooms->setGeneration(1, 'awaiting_confirmation');
        self::assertSame('awaiting_confirmation', $this->rooms->generation(1)['status']);

        $this->rooms->requestCancel(1);
        self::assertTrue((bool) $this->rooms->generation(1)['cancel_requested']);
    }
}
