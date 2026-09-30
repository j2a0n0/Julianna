<?php

declare(strict_types=1);

namespace Unit\app\Domain\ProjectAgent;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\SQLiteConnection;
use Leantime\Core\Auth\Permissions\PermissionService;
use Leantime\Domain\ProjectAgent\Repositories\ProjectAgentRepository;
use Leantime\Domain\ProjectAgent\Services\ProjectAgent;
use PDO;
use PHPUnit\Framework\TestCase;

final class ProjectAgentQueueTest extends TestCase
{
    public function test_event_and_daily_runs_are_deduplicated_and_abandoned_runs_do_not_replay(): void
    {
        $db = new SQLiteConnection(new PDO('sqlite::memory:'));
        $schema = $db->getSchemaBuilder();
        $schema->create('zp_user', static function (Blueprint $table): void {
            $table->id(); $table->string('status'); $table->string('role'); $table->integer('clientId')->nullable();
        });
        $schema->create('julianna_auth_accounts', static function (Blueprint $table): void {
            $table->id(); $table->integer('user_id'); $table->string('state');
        });
        $schema->create('zp_projects', static function (Blueprint $table): void {
            $table->id(); $table->string('state'); $table->string('psettings'); $table->integer('clientId')->nullable();
        });
        $schema->create('zp_relationuserproject', static function (Blueprint $table): void {
            $table->integer('userId'); $table->integer('projectId');
        });
        $schema->create('zp_tickets', static function (Blueprint $table): void {
            $table->id(); $table->integer('projectId'); $table->dateTime('modified');
        });
        $schema->create('julianna_agent_projects', static function (Blueprint $table): void {
            $table->integer('project_id')->primary(); $table->boolean('enabled'); $table->boolean('paused');
            $table->integer('enabled_by_user_id')->nullable(); $table->dateTime('created_at'); $table->dateTime('updated_at');
        });
        $schema->create('julianna_agent_runs', static function (Blueprint $table): void {
            $table->id(); $table->integer('project_id'); $table->integer('actor_user_id');
            $table->string('trigger'); $table->char('idempotency_key', 64)->unique(); $table->string('status');
            $table->string('summary')->nullable(); $table->dateTime('created_at'); $table->dateTime('started_at')->nullable();
            $table->dateTime('heartbeat_at')->nullable();
            $table->dateTime('finished_at')->nullable();
        });
        $schema->create('zp_queue', static function (Blueprint $table): void {
            $table->string('msghash')->primary(); $table->string('channel'); $table->integer('userId');
            $table->string('subject'); $table->text('message'); $table->dateTime('thedate'); $table->integer('projectId');
        });
        $db->table('zp_user')->insert(['id' => 5, 'status' => 'a', 'role' => '20']);
        $db->table('julianna_auth_accounts')->insert(['user_id' => 5, 'state' => 'active']);
        $db->table('zp_projects')->insert(['id' => 9, 'state' => '0', 'psettings' => 'private']);
        $db->table('zp_relationuserproject')->insert(['userId' => 5, 'projectId' => 9]);
        $db->table('zp_tickets')->insert(['id' => 23, 'projectId' => 9, 'modified' => '2026-09-23 13:00:00']);
        $db->table('julianna_agent_projects')->insert([
            'project_id' => 9, 'enabled' => true, 'paused' => false,
            'enabled_by_user_id' => 5, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
        ]);
        $store = new ProjectAgentRepository($db);
        $agent = new ProjectAgent($store, $this->createMock(PermissionService::class));

        $event = $agent->enqueueTicketEvent(23, 'updated');
        self::assertSame($event['id'], $agent->enqueueTicketEvent(23, 'updated')['id']);
        self::assertSame(1, $db->table('zp_queue')->count());
        $agent->enqueueDailyReviews();
        $agent->enqueueDailyReviews();
        self::assertSame(2, $db->table('zp_queue')->count());
        self::assertSame(2, $db->table('julianna_agent_runs')->count());

        $runId = (int) $event['id'];
        self::assertTrue($store->claimRun($runId));
        self::assertFalse($store->claimRun($runId));
        self::assertFalse($store->markAbandonedRun($runId));
        $db->table('julianna_agent_runs')->where('id', $runId)->update(['started_at' => '2026-01-01 00:00:00']);
        self::assertFalse($store->markAbandonedRun($runId)); // Live heartbeat beats old start time.
        $db->table('julianna_agent_runs')->where('id', $runId)->update(['heartbeat_at' => '2026-01-01 00:00:00']);
        self::assertTrue($store->markAbandonedRun($runId));
        self::assertSame('needs_review', $store->run($runId)['status']);
        self::assertFalse($store->claimRun($runId));
    }
}
