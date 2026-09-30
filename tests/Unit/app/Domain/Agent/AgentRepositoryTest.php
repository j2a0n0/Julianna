<?php

declare(strict_types=1);

namespace Unit\app\Domain\Agent;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\SQLiteConnection;
use Leantime\Core\Exceptions\AuthorizationException;
use Leantime\Domain\Agent\Repositories\AgentRepository;
use Leantime\Domain\ProjectAgent\Repositories\ProjectAgentRepository;
use PDO;
use PHPUnit\Framework\TestCase;

final class AgentRepositoryTest extends TestCase
{
    private SQLiteConnection $db;

    private AgentRepository $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = new SQLiteConnection(new PDO('sqlite::memory:'));
        $schema = $this->db->getSchemaBuilder();
        $schema->create('zp_user', static function (Blueprint $table): void {
            $table->id(); $table->string('status'); $table->string('role'); $table->unsignedBigInteger('clientId')->nullable();
        });
        $schema->create('julianna_auth_accounts', static function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('user_id'); $table->string('state');
        });
        $schema->create('zp_projects', static function (Blueprint $table): void {
            $table->id(); $table->string('state'); $table->string('psettings'); $table->unsignedBigInteger('clientId')->nullable();
        });
        $schema->create('zp_relationuserproject', static function (Blueprint $table): void {
            $table->unsignedBigInteger('userId'); $table->unsignedBigInteger('projectId');
        });
        $schema->create('julianna_agent_conversations', static function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('owner_user_id'); $table->unsignedBigInteger('project_id')->nullable();
            $table->string('title'); $table->string('state'); $table->string('page_path')->nullable();
            $table->dateTime('created_at'); $table->dateTime('updated_at');
        });
        $schema->create('julianna_agent_turns', static function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('conversation_id'); $table->string('role');
            $table->longText('content'); $table->json('metadata_json')->nullable(); $table->char('client_key', 64)->nullable();
            $table->dateTime('created_at'); $table->unique(['conversation_id', 'client_key']);
        });
        $schema->create('julianna_agent_tool_receipts', static function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('conversation_id'); $table->unsignedBigInteger('actor_user_id');
            $table->unsignedBigInteger('project_id')->nullable(); $table->string('tool_call_id');
            $table->string('tool_name'); $table->char('arguments_hash', 64); $table->string('effect');
            $table->string('status'); $table->json('result_json')->nullable(); $table->dateTime('created_at');
            $table->dateTime('finished_at')->nullable(); $table->unique(['conversation_id', 'tool_call_id']);
        });
        $schema->create('julianna_agent_drafts', static function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('conversation_id'); $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('actor_user_id'); $table->string('tool_name'); $table->json('arguments_json');
            $table->text('reason'); $table->string('status'); $table->dateTime('created_at'); $table->dateTime('updated_at');
        });
        $schema->create('julianna_agent_questions', static function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('conversation_id'); $table->unsignedBigInteger('project_id')->nullable();
            $table->longText('question'); $table->string('status'); $table->dateTime('created_at'); $table->dateTime('resolved_at')->nullable();
        });
        $this->db->table('zp_user')->insert(['id' => 7, 'status' => 'a', 'role' => '20']);
        $this->db->table('julianna_auth_accounts')->insert(['user_id' => 7, 'state' => 'active']);
        $this->db->table('zp_projects')->insert([
            ['id' => 11, 'state' => '0', 'psettings' => 'private'],
            ['id' => 12, 'state' => '0', 'psettings' => 'private'],
        ]);
        $this->db->table('zp_relationuserproject')->insert(['userId' => 7, 'projectId' => 11]);
        $this->agent = new AgentRepository($this->db, new ProjectAgentRepository($this->db));
    }

    public function test_conversation_and_transcript_persist_with_project_scope(): void
    {
        $conversation = $this->agent->createConversation(7, 11, '/projects/11');
        self::assertSame(11, (int) $conversation['project_id']);
        self::assertSame('/projects/11', $conversation['page_path']);
        $key = hash('sha256', 'request-123456789');
        $this->agent->addTurn((int) $conversation['id'], 'user', 'List tasks', clientKey: $key);
        $this->agent->addTurn((int) $conversation['id'], 'assistant', 'I found one task.');
        self::assertCount(2, $this->agent->turns((int) $conversation['id']));
        self::assertSame($key, $this->agent->turnByClientKey((int) $conversation['id'], $key)['client_key']);
        self::assertCount(1, $this->agent->conversations(7, 11));

        $this->db->table('zp_relationuserproject')->delete();
        $this->expectException(AuthorizationException::class);
        $this->agent->conversation((int) $conversation['id'], 7);
    }

    public function test_receipts_are_single_use_and_draft_never_publishes_itself(): void
    {
        $conversation = $this->agent->createConversation(7, 11, null);
        $id = (int) $conversation['id'];
        $hash = hash('sha256', 'arguments');
        self::assertTrue($this->agent->createReceipt($id, 7, 11, 'call-1', 'addTask', $hash, 'write'));
        self::assertFalse($this->agent->createReceipt($id, 7, 11, 'call-1', 'addTask', $hash, 'write'));
        self::assertSame('pending', $this->agent->receipt($id, 'call-1')['status']);
        $this->agent->finishReceipt($id, 'call-1', ['ok' => true, 'text' => 'Created task #1']);
        self::assertSame('completed', $this->agent->receipt($id, 'call-1')['status']);

        $draft = $this->agent->createDraft($id, 11, 7, 'addComment', ['content' => 'Hello'], 'Review first');
        self::assertSame('draft', $draft['status']);
        self::assertCount(1, $this->agent->drafts(11));
        self::assertTrue($this->agent->setDraftStatus((int) $draft['id'], 'draft', 'publishing'));
        self::assertTrue($this->agent->setDraftStatus((int) $draft['id'], 'publishing', 'failed'));
        self::assertSame('failed', $this->agent->drafts(11)[0]['status']);
        self::assertTrue($this->agent->setDraftStatus((int) $draft['id'], 'failed', 'discarded'));
        self::assertSame([], $this->agent->drafts(11));
    }

    public function test_disabled_account_blocks_new_and_existing_conversations(): void
    {
        $conversation = $this->agent->createConversation(7, null, null);
        $this->db->table('julianna_auth_accounts')->where('user_id', 7)->update(['state' => 'disabled']);
        $this->expectException(AuthorizationException::class);
        $this->agent->conversation((int) $conversation['id'], 7);
    }

    public function test_transcript_window_contains_newest_turns_in_chronological_order(): void
    {
        $conversation = $this->agent->createConversation(7, null, null);
        $id = (int) $conversation['id'];
        for ($number = 1; $number <= 305; $number++) {
            $this->agent->addTurn($id, 'user', 'message '.$number);
        }

        $recent = $this->agent->turns($id);
        self::assertCount(300, $recent);
        self::assertSame('message 6', $recent[0]['content']);
        self::assertSame('message 305', $recent[299]['content']);
        self::assertSame(305, $this->db->table('julianna_agent_turns')->where('conversation_id', $id)->count());
    }

    public function test_project_questions_remain_private_to_conversation_owner(): void
    {
        $conversation = $this->agent->createConversation(7, 11, null);
        $this->agent->addQuestion((int) $conversation['id'], 11, 'What is the budget?');
        self::assertCount(1, $this->agent->questions(11, 7));

        $this->db->table('zp_user')->insert(['id' => 8, 'status' => 'a', 'role' => '20']);
        $this->db->table('julianna_auth_accounts')->insert(['user_id' => 8, 'state' => 'active']);
        $this->db->table('zp_relationuserproject')->insert(['userId' => 8, 'projectId' => 11]);
        self::assertSame([], $this->agent->questions(11, 8));
    }

    public function test_unscoped_question_is_visible_only_to_its_owner(): void
    {
        $conversation = $this->agent->createConversation(7, null, null);
        $this->agent->addQuestion((int) $conversation['id'], null, 'Which project?');
        self::assertSame('Which project?', $this->agent->questions(null, 7)[0]['question']);
        self::assertSame([], $this->agent->questions(11, 7));

        $this->db->table('zp_user')->insert(['id' => 8, 'status' => 'a', 'role' => '20']);
        $this->db->table('julianna_auth_accounts')->insert(['user_id' => 8, 'state' => 'active']);
        self::assertSame([], $this->agent->questions(null, 8));
    }
}
