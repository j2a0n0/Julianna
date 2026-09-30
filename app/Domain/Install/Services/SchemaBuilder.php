<?php

namespace Leantime\Domain\Install\Services;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Leantime\Core\Configuration\AppSettings;

/**
 * SchemaBuilder provides database-agnostic table creation for Leantime installation.
 *
 * This class uses Laravel's Schema Builder to create all database tables,
 * supporting both MySQL and PostgreSQL. It replaces the raw SQL DDL statements
 * that were previously MySQL-specific.
 */
class SchemaBuilder
{
    public function __construct(
        private AppSettings $appSettings
    ) {}

    /**
     * Create all database tables for a fresh Leantime installation.
     *
     * @throws \Exception If table creation fails
     */
    public function createAllTables(): void
    {
        $this->createCalendarTable();
        $this->createCanvasTable();
        $this->createCanvasItemsTable();
        $this->createApprovalsTable();
        $this->createClientsTable();
        $this->createCommentTable();
        $this->createFileTable();
        $this->createGcallinksTable();
        $this->createNoteTable();
        $this->createProjectsTable();
        $this->createPunchClockTable();
        $this->createReadTable();
        $this->createRelationUserProjectTable();
        $this->createTicketHistoryTable();
        $this->createTicketsTable();
        $this->createGoalHistoryTable();
        $this->createTimesheetsTable();
        $this->createUserTable();
        $this->createSprintsTable();
        $this->createStatsTable();
        $this->createSettingsTable();
        $this->createAuditTable();
        $this->createQueueTable();
        $this->createPluginsTable();
        $this->createNotificationsTable();
        $this->createEntityRelationshipTable();
        $this->createIntegrationTable();
        $this->createReactionsTable();
        $this->createAccessTokensTable();
        $this->createJobsTable();
        $this->createRecurringPatternsTable();
        $this->createWorkStructureTables();
        $this->createRolesTable();
        $this->createPermissionsTable();
        $this->createRolePermissionsTable();
        $this->createJuliannaAuthTables();
        $this->createIdeaRoomTables();
        $this->createIdeaRoomChatTables();
        $this->createIdeaRoomGraphTables();
        $this->createIdeaRoomMcpColumns();
        $this->createIdeaRoomHistoryTable();
        $this->createProjectAgentTables();
        $this->createWhiteboardTables();
        $this->createAgentHarnessTables();
        $this->createAgentActionClaimsTable();
        $this->createAgentAiSettingsTable();
        $this->createAgentWebSearchSettingsTable();
    }

    /**
     * Insert initial data after tables are created.
     *
     * @param  array  $values  Installation form values containing company, email, firstname, lastname
     * @param  string  $pwReset  Password reset token
     */
    public function insertInitialData(array $values, string $pwReset): void
    {
        // Insert initial client/company
        DB::table('zp_clients')->insert([
            'id' => 1,
            'name' => $values['company'],
            'street' => '',
            'zip' => 0,
            'city' => '',
            'state' => '',
            'country' => '',
            'phone' => '',
            'internet' => '',
            'published' => null,
            'age' => null,
            'email' => '',
        ]);

        // Insert initial admin user (status 'i' = inactive, requires password reset)
        DB::table('zp_user')->insert([
            'id' => 1,
            'username' => $values['email'],
            'password' => '',
            'firstname' => $values['firstname'],
            'lastname' => $values['lastname'],
            'phone' => '',
            'profileId' => '',
            'lastlogin' => null,
            'lastpwd_change' => null,
            'status' => 'i',
            'expires' => null,
            'role' => '50',
            'session' => '',
            'sessiontime' => '',
            'wage' => 0,
            'hours' => 0,
            'description' => null,
            'clientId' => 0,
            'notifications' => 1,
            'createdOn' => now(),
            'pwReset' => $pwReset,
        ]);

        // Julianna owns credentials independently from the legacy application
        // user table. The installer token doubles as the first owner's
        // short-lived password-setup token; only its SHA-256 digest is stored.
        $now = now();
        $accountId = DB::table('julianna_auth_accounts')->insertGetId([
            'user_id' => 1,
            'email_normalized' => mb_strtolower(trim($values['email']), 'UTF-8'),
            'display_name' => trim($values['firstname'].' '.$values['lastname']),
            'password_hash' => password_hash(bin2hex(random_bytes(32)), PASSWORD_ARGON2ID),
            'state' => 'active',
            'email_verified_at' => $now,
            'approved_at' => $now,
            'approved_by_user_id' => 1,
            'rejected_at' => null,
            'disabled_at' => null,
            'mfa_confirmed_at' => null,
            'password_changed_at' => null,
            'session_version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('julianna_auth_tokens')->insert([
            'account_id' => $accountId,
            'purpose' => 'password_reset',
            'token_hash' => hash('sha256', $pwReset),
            'expires_at' => $now->copy()->addHours(24),
            'consumed_at' => null,
            'created_at' => $now,
        ]);

        // Insert initial settings
        DB::table('zp_settings')->insert([
            ['key' => 'db-version', 'value' => $this->appSettings->dbVersion],
        ]);

        // zp_clients and zp_user are seeded with explicit id = 1 above. MySQL
        // auto-advances AUTO_INCREMENT after an explicit-id insert; Postgres
        // leaves the BIGSERIAL sequence at 1, so the next auto-id INSERT (e.g.
        // OIDC JIT user creation) collides on the primary key. Re-sync the
        // sequences to MAX(id) on Postgres. No-op on MySQL. (#3380)
        $this->resyncSequences(['zp_clients', 'zp_user']);
    }

    /**
     * Re-sync BIGSERIAL sequences to MAX(id) for tables seeded with explicit
     * ids. Postgres-only; a no-op on every other driver.
     *
     * @param  string[]  $tables  Tables whose `id` sequence should be advanced.
     */
    private function resyncSequences(array $tables): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach ($tables as $table) {
            DB::statement(
                "SELECT setval(pg_get_serial_sequence(?, 'id'), COALESCE((SELECT MAX(id) FROM {$table}), 1))",
                [$table]
            );
        }
    }

    /**
     * Create zp_calendar table.
     */
    private function createCalendarTable(): void
    {
        Schema::create('zp_calendar', function (Blueprint $table) {
            $table->id();
            $table->integer('userId')->nullable();
            $table->dateTime('dateFrom')->nullable();
            $table->dateTime('dateTo')->nullable();
            $table->text('description')->nullable();
            $table->string('kind', 255)->nullable();
            $table->string('allDay', 10)->nullable();

            $table->index(['userId', 'dateFrom', 'dateTo'], 'idx_calendar_userId_dateFrom_dateTo');
        });
    }

    /**
     * Create zp_canvas table.
     */
    private function createCanvasTable(): void
    {
        Schema::create('zp_canvas', function (Blueprint $table) {
            $table->id();
            $table->string('title', 255)->nullable();
            $table->integer('author')->nullable();
            $table->dateTime('created')->nullable();
            $table->integer('projectId')->nullable();
            $table->string('type', 45)->nullable();
            $table->text('description')->nullable();
            $table->string('color', 50)->nullable()->default('ocean');
            $table->dateTime('modified')->nullable();

            $table->index(['projectId', 'type'], 'ProjectIdType');
            $table->index(['type', 'id'], 'idx_canvas_type_id');
        });
    }

    /**
     * Create zp_canvas_items table.
     */
    private function createCanvasItemsTable(): void
    {
        Schema::create('zp_canvas_items', function (Blueprint $table) {
            $table->id();
            $table->text('description')->nullable();
            $table->text('assumptions')->nullable();
            $table->text('data')->nullable();
            $table->text('conclusion')->nullable();
            $table->text('why_this_matters')->nullable();
            $table->text('starting_picture')->nullable();
            $table->string('box', 255)->nullable();
            $table->integer('author')->nullable();
            $table->dateTime('created')->nullable();
            $table->dateTime('modified')->nullable();
            $table->integer('canvasId')->nullable();
            $table->integer('sortindex')->nullable();
            $table->string('status', 255)->nullable();
            $table->string('relates', 255)->nullable();
            $table->string('milestoneId', 255)->nullable();
            $table->string('title', 255)->nullable();
            $table->integer('parent')->nullable();
            $table->integer('featured')->nullable();
            $table->text('tags')->nullable();
            $table->integer('kpi')->nullable();
            $table->text('data1')->nullable();
            $table->text('data2')->nullable();
            $table->text('data3')->nullable();
            $table->text('data4')->nullable();
            $table->text('data5')->nullable();
            $table->dateTime('startDate')->nullable();
            $table->dateTime('endDate')->nullable();
            $table->text('setting')->nullable();
            $table->string('metricType', 45)->nullable();
            $table->decimal('startValue', 10, 2)->nullable();
            $table->decimal('currentValue', 10, 2)->nullable();
            $table->decimal('endValue', 10, 2)->nullable();
            $table->integer('impact')->nullable();
            $table->integer('effort')->nullable();
            $table->integer('probability')->nullable();
            $table->text('action')->nullable();
            $table->integer('assignedTo')->nullable();

            $table->index(['canvasId', 'box'], 'CanvasLookUp');
            $table->index(['box', 'milestoneId'], 'idx_canvas_items_box_milestoneId');
            $table->index(['box', 'status', 'author'], 'idx_canvas_items_box_status_author');
            $table->index(['parent', 'title'], 'idx_canvas_items_parent_title');
        });
    }

    /**
     * Create zp_approvals table.
     */
    private function createApprovalsTable(): void
    {
        Schema::create('zp_approvals', function (Blueprint $table) {
            $table->id();
            $table->string('module', 100)->nullable();
            $table->integer('entityId')->nullable();
            $table->integer('requestorId')->nullable();
            $table->integer('approverId')->nullable();
            $table->integer('approvalStatus')->nullable();
            $table->dateTime('requestedOn')->nullable();
            $table->dateTime('lastStatusChange')->nullable();
        });
    }

    /**
     * Create zp_clients table.
     */
    private function createClientsTable(): void
    {
        Schema::create('zp_clients', function (Blueprint $table) {
            $table->id();
            $table->string('name', 200)->nullable();
            $table->string('street', 200)->nullable();
            $table->integer('zip')->nullable();
            $table->string('city', 50)->nullable();
            $table->string('state', 50)->nullable();
            $table->string('country', 50)->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('internet', 200)->nullable();
            $table->integer('published')->nullable();
            $table->integer('age')->nullable();
            $table->string('email', 255)->nullable();
            $table->dateTime('modified')->nullable();
        });
    }

    /**
     * Create zp_comment table.
     */
    private function createCommentTable(): void
    {
        Schema::create('zp_comment', function (Blueprint $table) {
            $table->id();
            $table->string('module', 200)->nullable();
            $table->integer('userId')->nullable();
            $table->integer('commentParent')->nullable();
            $table->dateTime('date')->nullable();
            $table->integer('moduleId')->nullable();
            $table->text('text')->nullable();
            $table->string('status', 50)->nullable();

            $table->index(['moduleId', 'module', 'commentParent'], 'idx_comment_moduleId_module_commentParent');
            $table->index(['userId', 'module'], 'idx_comment_userId_module');
            $table->index(['moduleId', 'module', 'date'], 'idx_comment_moduleId_module_date');
        });
    }

    /**
     * Create zp_file table.
     *
     * Note: MySQL uses ENUM for module, but for cross-database compatibility
     * we use a string column with application-level validation.
     */
    private function createFileTable(): void
    {
        Schema::create('zp_file', function (Blueprint $table) {
            $table->id();
            // Using string instead of enum for PostgreSQL compatibility
            // Valid values: project, ticket, client, user, lead, export, private
            $table->string('module', 50)->nullable();
            $table->integer('moduleId')->nullable();
            $table->integer('userId')->nullable();
            $table->string('extension', 10)->nullable();
            $table->string('encName', 255)->nullable();
            $table->string('realName', 255)->nullable();
            $table->dateTime('date')->nullable();

            $table->index(['module', 'moduleId', 'userId'], 'idx_file_module_moduleId_userId');
        });
    }

    /**
     * Create zp_gcallinks table.
     */
    private function createGcallinksTable(): void
    {
        Schema::create('zp_gcallinks', function (Blueprint $table) {
            $table->id();
            $table->integer('userId')->nullable();
            $table->text('url')->nullable();
            $table->string('name', 255)->nullable();
            $table->string('colorClass', 100)->nullable();

            $table->index(['userId'], 'idx_gcallinks_userId');
        });
    }

    /**
     * Create zp_note table.
     */
    private function createNoteTable(): void
    {
        Schema::create('zp_note', function (Blueprint $table) {
            $table->id();
            $table->integer('userId')->nullable();
            $table->string('title', 255)->nullable();
            $table->text('description')->nullable();
        });
    }

    /**
     * Create zp_projects table.
     */
    private function createProjectsTable(): void
    {
        Schema::create('zp_projects', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->nullable();
            $table->integer('clientId')->nullable();
            $table->text('details')->nullable();
            $table->integer('state')->nullable();
            $table->string('hourBudget', 255)->nullable()->default('');
            $table->integer('dollarBudget')->nullable();
            $table->integer('active')->nullable();
            $table->text('menuType')->nullable();
            $table->text('psettings')->nullable();
            $table->integer('parent')->nullable();
            $table->string('type', 45)->nullable();
            $table->dateTime('start')->nullable();
            $table->dateTime('end')->nullable();
            $table->dateTime('created')->nullable();
            $table->dateTime('modified')->nullable();
            $table->text('avatar')->nullable();
            $table->text('cover')->nullable();
            $table->integer('sortIndex')->nullable();
        });
    }

    /**
     * Create zp_punch_clock table.
     *
     * Note: Has a composite primary key (id, userId).
     */
    private function createPunchClockTable(): void
    {
        Schema::create('zp_punch_clock', function (Blueprint $table) {
            $table->id();
            $table->integer('userId');
            $table->integer('minutes')->nullable();
            $table->integer('hours')->nullable();
            $table->integer('punchIn')->nullable();

            $table->index(['userId'], 'idx_punch_clock_userId');
        });
    }

    /**
     * Create zp_read table.
     *
     * Note: MySQL uses ENUM for module, but for cross-database compatibility
     * we use a string column with application-level validation.
     */
    private function createReadTable(): void
    {
        Schema::create('zp_read', function (Blueprint $table) {
            $table->id();
            // Using string instead of enum for PostgreSQL compatibility
            // Valid values: ticket, message
            $table->string('module', 50)->nullable();
            $table->integer('moduleId')->nullable();
            $table->integer('userId')->nullable();

            $table->index(['userId', 'module', 'moduleId'], 'idx_read_userId_module_moduleId');
        });
    }

    /**
     * Create zp_relationuserproject table (junction table for user-project relationships).
     */
    private function createRelationUserProjectTable(): void
    {
        Schema::create('zp_relationuserproject', function (Blueprint $table) {
            $table->id();
            $table->integer('userId')->nullable();
            $table->integer('projectId')->nullable();
            $table->integer('wage')->nullable();
            $table->string('projectRole', 20)->nullable();

            $table->index(['projectId'], 'zp_relationuserproject_projectId_index');
            $table->index(['userId'], 'zp_relationuserproject_userId_index');
            $table->index(['userId', 'projectId'], 'idx_relationuserproject_userId_projectId');
        });
    }

    /**
     * Create zp_tickethistory table.
     */
    private function createTicketHistoryTable(): void
    {
        Schema::create('zp_tickethistory', function (Blueprint $table) {
            $table->id();
            $table->integer('userId')->nullable();
            $table->integer('ticketId')->nullable();
            $table->string('changeType', 255)->nullable();
            $table->string('changeValue', 150)->nullable();
            $table->dateTime('dateModified')->nullable();

            $table->index(['ticketId'], 'idx_tickethistory_ticketId');
        });
    }

    /**
     * Create zp_goal_history table (append-only record of goal metric values over time).
     */
    private function createGoalHistoryTable(): void
    {
        Schema::create('zp_goal_history', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('itemId');
            $table->double('value')->nullable();
            $table->integer('userId')->nullable();
            $table->dateTime('dateRecorded')->nullable();

            $table->index(['itemId', 'dateRecorded'], 'idx_goal_history_item_date');
        });
    }

    /**
     * Create zp_tickets table.
     */
    private function createTicketsTable(): void
    {
        Schema::create('zp_tickets', function (Blueprint $table) {
            $table->id();
            $table->integer('projectId')->nullable();
            $table->string('headline', 255)->nullable();
            $table->text('description')->nullable();
            $table->text('acceptanceCriteria')->nullable();
            $table->text('outcomeImpact')->nullable();
            $table->dateTime('date')->nullable();
            $table->dateTime('dateToFinish')->nullable();
            $table->string('priority', 60)->nullable();
            $table->integer('status')->nullable();
            $table->integer('userId')->nullable();
            $table->string('os', 30)->nullable();
            $table->string('browser', 30)->nullable();
            $table->string('resolution', 30)->nullable();
            $table->string('component', 100)->nullable();
            $table->string('version', 20)->nullable();
            $table->string('url', 100)->nullable();
            $table->integer('dependingTicketId')->nullable();
            $table->dateTime('editFrom')->nullable();
            $table->dateTime('editTo')->nullable();
            $table->string('editorId', 75)->nullable();
            $table->float('planHours')->nullable();
            $table->float('hourRemaining')->nullable();
            $table->string('type', 255)->nullable();
            $table->integer('production')->default(0);
            $table->integer('staging')->default(0);
            $table->float('storypoints')->nullable();
            $table->integer('sprint')->nullable();
            $table->bigInteger('sortindex')->nullable();
            $table->bigInteger('kanbanSortIndex')->nullable();
            $table->string('tags', 255)->nullable();
            $table->integer('milestoneid')->nullable();
            $table->integer('leancanvasitemid')->nullable();
            $table->integer('retrospectiveid')->nullable();
            $table->integer('ideaid')->nullable();
            $table->string('zp_ticketscol', 45)->nullable();
            $table->dateTime('modified')->nullable();

            $table->index(['projectId', 'userId'], 'ProjectUserId');
            $table->index(['status', 'sprint'], 'StatusSprint');
            $table->index(['sortindex'], 'Sorting');
            $table->index(['editorId'], 'idx_tickets_editorId');
            $table->index(['milestoneid'], 'idx_tickets_milestoneid');
            $table->index(['editFrom'], 'idx_tickets_editFrom');
            $table->index(['editTo'], 'idx_tickets_editTo');
            $table->index(['dateToFinish'], 'idx_tickets_dateToFinish');
            $table->index(['modified'], 'idx_tickets_modified');
            $table->index(['projectId', 'status'], 'idx_tickets_projectId_status');
            $table->index(['projectId', 'type'], 'idx_tickets_projectId_type');
            $table->index(['status', 'type'], 'idx_tickets_status_type');
            $table->index(['dependingTicketId'], 'idx_tickets_dependingTicketId');
        });
    }

    /**
     * Create zp_timesheets table.
     */
    private function createTimesheetsTable(): void
    {
        Schema::create('zp_timesheets', function (Blueprint $table) {
            $table->id();
            $table->integer('userId')->nullable();
            $table->integer('ticketId')->nullable();
            $table->dateTime('workDate')->nullable();
            $table->float('hours')->nullable();
            $table->text('description')->nullable();
            $table->string('kind', 175)->nullable();
            $table->integer('invoicedEmpl')->nullable();
            $table->integer('invoicedComp')->nullable();
            $table->dateTime('invoicedEmplDate')->nullable();
            $table->dateTime('invoicedCompDate')->nullable();
            $table->string('rate', 255)->nullable();
            $table->integer('paid')->nullable();
            $table->dateTime('paidDate')->nullable();
            $table->dateTime('modified')->nullable();

            $table->unique(['userId', 'ticketId', 'workDate', 'kind'], 'Unique');
            $table->index(['ticketId'], 'idx_timesheets_ticketId');
            $table->index(['userId', 'workDate'], 'idx_timesheets_userId_workDate');
            $table->index(['ticketId', 'workDate'], 'idx_timesheets_ticketId_workDate');
        });
    }

    /**
     * Create zp_user table.
     */
    private function createUserTable(): void
    {
        Schema::create('zp_user', function (Blueprint $table) {
            $table->id();
            $table->string('username', 175);
            $table->string('password', 255)->nullable()->default('');
            $table->string('firstname', 100)->nullable()->default('');
            $table->string('lastname', 100)->nullable()->default('');
            $table->string('phone', 25)->nullable()->default('');
            $table->string('profileId', 100)->nullable()->default('');
            $table->dateTime('lastlogin')->nullable();
            $table->string('status', 1)->default('A');
            $table->dateTime('expires')->nullable();
            $table->string('role', 200);
            $table->string('session', 100)->nullable();
            $table->string('sessiontime', 50)->nullable();
            $table->integer('wage')->nullable();
            $table->integer('hours')->nullable();
            $table->integer('weekly_hours')->nullable();
            $table->string('employment_type', 20)->nullable();
            $table->text('description')->nullable();
            $table->integer('clientId')->nullable();
            $table->integer('notifications')->nullable();
            $table->string('pwReset', 100)->nullable();
            $table->dateTime('pwResetExpiration')->nullable();
            $table->integer('pwResetCount')->nullable();
            $table->tinyInteger('forcePwReset')->nullable();
            $table->dateTime('lastpwd_change')->nullable();
            $table->text('settings')->nullable();
            $table->tinyInteger('twoFAEnabled')->default(0);
            $table->string('twoFASecret', 200)->nullable();
            $table->dateTime('createdOn')->nullable();
            $table->string('source', 200)->nullable();
            $table->string('jobTitle', 200)->nullable();
            $table->string('jobLevel', 50)->nullable();
            $table->string('department', 200)->nullable();
            $table->dateTime('modified')->nullable();

            $table->unique(['username'], 'username');
            $table->index(['clientId'], 'idx_user_clientId');
        });
    }

    /**
     * Create zp_sprints table.
     */
    private function createSprintsTable(): void
    {
        Schema::create('zp_sprints', function (Blueprint $table) {
            $table->id();
            $table->integer('projectId')->nullable();
            $table->string('name', 45)->nullable();
            $table->dateTime('startDate')->nullable();
            $table->dateTime('endDate')->nullable();
            $table->dateTime('modified')->nullable();

            $table->index(['projectId', 'startDate', 'endDate'], 'idx_sprints_projectId_startDate_endDate');
        });
    }

    /**
     * Create zp_stats table.
     *
     * Note: This table has no primary key, only indexes.
     */
    private function createStatsTable(): void
    {
        Schema::create('zp_stats', function (Blueprint $table) {
            $table->integer('sprintId')->nullable();
            $table->integer('projectId')->nullable();
            $table->dateTime('date')->nullable();
            $table->integer('sum_todos')->nullable();
            $table->integer('sum_open_todos')->nullable();
            $table->integer('sum_progres_todos')->nullable();
            $table->integer('sum_closed_todos')->nullable();
            $table->float('sum_planned_hours')->nullable();
            $table->float('sum_estremaining_hours')->nullable();
            $table->float('sum_logged_hours')->nullable();
            $table->integer('sum_points')->nullable();
            $table->integer('sum_points_done')->nullable();
            $table->integer('sum_points_progress')->nullable();
            $table->integer('sum_points_open')->nullable();
            $table->integer('sum_todos_xs')->nullable();
            $table->integer('sum_todos_s')->nullable();
            $table->integer('sum_todos_m')->nullable();
            $table->integer('sum_todos_l')->nullable();
            $table->integer('sum_todos_xl')->nullable();
            $table->integer('sum_todos_xxl')->nullable();
            $table->integer('sum_todos_none')->nullable();
            $table->integer('tickets')->nullable();
            $table->float('daily_avg_hours_booked_todo')->nullable();
            $table->float('daily_avg_hours_booked_point')->nullable();
            $table->float('daily_avg_hours_planned_todo')->nullable();
            $table->float('daily_avg_hours_planned_point')->nullable();
            $table->float('daily_avg_hours_remaining_point')->nullable();
            $table->float('daily_avg_hours_remaining_todo')->nullable();
            $table->integer('sum_teammembers')->nullable();

            $table->index(['projectId', 'sprintId'], 'idx_stats_projectId');
            $table->index(['projectId', 'sprintId', 'date'], 'idx_stats_projectId_sprintId_date');
            $table->index(['sprintId', 'date'], 'idx_stats_sprintId_date');
        });
    }

    /**
     * Create zp_settings table (key-value store).
     */
    private function createSettingsTable(): void
    {
        Schema::create('zp_settings', function (Blueprint $table) {
            $table->string('key', 175)->primary();
            $table->text('value')->nullable();
        });
    }

    /**
     * Create zp_roles table — the DB-backed role definitions for the native
     * permission engine. Ships the six built-in roles (seeded separately) and is
     * extensible with custom roles. `level` preserves the legacy "at least"
     * hierarchy (5..50); `isSystem` flags the built-ins as protected.
     */
    private function createRolesTable(): void
    {
        Schema::create('zp_roles', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50);
            $table->string('displayName', 100)->nullable();
            $table->integer('level');
            $table->tinyInteger('isSystem')->default(0);
            $table->text('description')->nullable();
            $table->dateTime('createdOn')->nullable();
            $table->dateTime('modified')->nullable();

            $table->unique(['name'], 'idx_roles_name');
            $table->index(['level'], 'idx_roles_level');
        });
    }

    /**
     * Create zp_permissions table — the `domain.action` vocabulary, synced from each
     * domain's ProvidesPermissions declarations by `permissions:sync`. `isProjectScoped`
     * marks capabilities evaluated against a project's role vs company-wide.
     */
    private function createPermissionsTable(): void
    {
        Schema::create('zp_permissions', function (Blueprint $table) {
            $table->id();
            $table->string('permissionKey', 150);
            $table->string('domain', 60);
            $table->string('action', 100);
            $table->string('label', 191)->nullable();
            $table->tinyInteger('isProjectScoped')->default(1);
            $table->dateTime('createdOn')->nullable();
            $table->dateTime('modified')->nullable();

            $table->unique(['permissionKey'], 'idx_permissions_key');
            $table->index(['domain'], 'idx_permissions_domain');
        });
    }

    /**
     * Create zp_role_permissions table — the many-to-many grant map linking roles to
     * the permissions they hold. Edited by an administrator (future role-management UI).
     */
    private function createRolePermissionsTable(): void
    {
        Schema::create('zp_role_permissions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('roleId');
            $table->unsignedBigInteger('permissionId');

            $table->unique(['roleId', 'permissionId'], 'idx_role_permissions_unique');
            $table->index(['roleId'], 'idx_role_permissions_roleId');
            $table->index(['permissionId'], 'idx_role_permissions_permissionId');
        });
    }

    /**
     * Create zp_audit table.
     */
    private function createAuditTable(): void
    {
        Schema::create('zp_audit', function (Blueprint $table) {
            $table->id();
            $table->integer('userId')->nullable();
            $table->integer('projectId')->nullable();
            $table->string('action', 45)->nullable();
            $table->string('entity', 45)->nullable();
            $table->integer('entityId')->nullable();
            $table->text('values')->nullable();
            $table->dateTime('date')->nullable();

            $table->index(['projectId'], 'idx_audit_projectId');
            $table->index(['projectId', 'action'], 'idx_audit_projectAction');
            $table->index(['projectId', 'entity', 'entityId'], 'idx_audit_projectEntityEntityId');
        });
    }

    /**
     * Create zp_queue table (message queue).
     */
    private function createQueueTable(): void
    {
        Schema::create('zp_queue', function (Blueprint $table) {
            $table->string('msghash', 50)->primary();
            $table->string('channel', 255)->nullable();
            $table->integer('userId');
            $table->string('subject', 255)->nullable();
            $table->text('message');
            $table->dateTime('thedate');
            $table->integer('projectId');

            $table->index(['projectId'], 'idx_queue_projectId');
            $table->index(['userId'], 'idx_queue_userId');
        });
    }

    /**
     * Create zp_plugins table.
     */
    private function createPluginsTable(): void
    {
        Schema::create('zp_plugins', function (Blueprint $table) {
            $table->id();
            $table->string('name', 45)->nullable();
            $table->tinyInteger('enabled')->nullable();
            $table->string('description', 255)->nullable();
            $table->string('version', 45)->nullable();
            $table->dateTime('installdate')->nullable();
            $table->string('foldername', 45)->nullable();
            $table->string('homepage', 255)->nullable();
            $table->string('authors', 255)->nullable();
            $table->text('license')->nullable();
            $table->string('format', 45)->nullable();
        });
    }

    /**
     * Create zp_notifications table.
     */
    private function createNotificationsTable(): void
    {
        Schema::create('zp_notifications', function (Blueprint $table) {
            $table->id();
            $table->integer('userId');
            $table->integer('read')->nullable();
            $table->string('type', 45)->nullable();
            $table->string('module', 45)->nullable();
            $table->integer('moduleId')->nullable();
            $table->dateTime('datetime')->nullable();
            $table->string('url', 255)->nullable();
            $table->integer('authorId')->nullable();
            $table->text('message')->nullable();

            $table->index(['userId'], 'idx_notifications_userId');
            $table->index(['userId', 'datetime'], 'idx_notifications_userId_datetime');
            $table->index(['userId', 'read'], 'idx_notifications_userId_read');
        });
    }

    /**
     * Create zp_entity_relationship table (polymorphic relationships).
     */
    private function createEntityRelationshipTable(): void
    {
        Schema::create('zp_entity_relationship', function (Blueprint $table) {
            $table->id();
            $table->integer('entityA')->nullable();
            $table->string('entityAType', 45)->nullable();
            $table->integer('entityB')->nullable();
            $table->string('entityBType', 45)->nullable();
            $table->string('relationship', 45)->nullable();
            $table->dateTime('createdOn')->nullable();
            $table->integer('createdBy')->nullable();
            $table->text('meta')->nullable();

            $table->index(['entityA', 'entityAType', 'relationship'], 'idx_entity_relationship_entityA');
            $table->index(['entityB', 'entityBType', 'relationship'], 'idx_entity_relationship_entityB');
        });
    }

    /**
     * Create zp_integration table.
     */
    private function createIntegrationTable(): void
    {
        Schema::create('zp_integration', function (Blueprint $table) {
            $table->id();
            $table->string('providerId', 45)->nullable();
            $table->string('method', 45)->nullable();
            $table->string('entity', 45)->nullable();
            $table->text('fields')->nullable();
            $table->string('schedule', 45)->nullable();
            $table->string('notes', 45)->nullable();
            $table->text('auth')->nullable();
            $table->string('meta', 45)->nullable();
            $table->dateTime('createdOn')->nullable();
            $table->integer('createdBy')->nullable();
            $table->string('lastSync', 45)->nullable();
        });
    }

    /**
     * Create zp_reactions table (emoji reactions).
     */
    private function createReactionsTable(): void
    {
        Schema::create('zp_reactions', function (Blueprint $table) {
            $table->id();
            $table->integer('userId')->nullable();
            $table->integer('moduleId')->nullable();
            $table->string('module', 45)->nullable();
            $table->string('reaction', 45)->nullable();
            $table->dateTime('date')->nullable();

            $table->index(['moduleId', 'module', 'reaction'], 'idx_reactions_entity');
            $table->index(['userId', 'moduleId', 'module', 'reaction'], 'idx_reactions_user');
        });
    }

    /**
     * Create zp_access_tokens table (Laravel Sanctum personal access tokens).
     *
     * Public because update_sql_30504() reuses it to self-heal installs that
     * reached that migration without the table (see #3706). Keeping one
     * definition here also keeps the create database-agnostic, unlike the raw
     * MySQL in update_sql_30400().
     */
    public function createAccessTokensTable(): void
    {
        Schema::create('zp_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('tokenable_type', 255);
            $table->unsignedBigInteger('tokenable_id');
            $table->string('name', 255);
            $table->string('token', 64);
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            $table->unique(['token'], 'personal_access_tokens_token_unique');
            $table->index(['tokenable_type', 'tokenable_id'], 'personal_access_tokens_tokenable_type_tokenable_id_index');
        });
    }

    /**
     * Create zp_jobs table (Laravel job queue).
     */
    private function createJobsTable(): void
    {
        Schema::create('zp_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('queue', 255);
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');

            $table->index(['queue'], 'zp_jobs_queue_index');
        });
    }

    /**
     * Create zp_recurring_patterns table.
     */
    private function createRecurringPatternsTable(): void
    {
        Schema::create('zp_recurring_patterns', function (Blueprint $table) {
            $table->id();
            $table->integer('entityId');
            $table->string('module', 50);
            $table->string('type', 50);
            $table->string('trigger', 50);
            $table->integer('interval')->default(1);
            $table->text('weekDays')->nullable();
            $table->integer('monthDay')->nullable();
            $table->text('months')->nullable();
            $table->string('action', 20)->default('reset');
            $table->dateTime('lastProcessed')->nullable();
            $table->dateTime('nextProcessingDate')->nullable();
            $table->tinyInteger('enabled')->default(1);

            $table->index(['entityId'], 'idx_recurring_patterns_entityId');
        });
    }

    /**
     * Create the WorkStructure tables: structure definitions, their element
     * types, intra-structure relationships, and cross-structure mappings.
     */
    private function createWorkStructureTables(): void
    {
        Schema::create('zp_work_structures', function (Blueprint $table) {
            $table->id();
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->string('type', 50)->default('custom');
            $table->integer('created_by')->nullable();
            $table->json('meta')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('modified_at')->nullable();

            $table->unique(['title'], 'idx_work_structures_title');
            $table->index(['type'], 'idx_work_structures_type');
        });

        Schema::create('zp_work_structure_elements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('structure_id');
            $table->string('type_key', 50);
            $table->string('label', 100);
            $table->text('description')->nullable();
            $table->string('domain_reference', 255)->nullable();
            $table->integer('sort_order')->default(0);
            $table->json('meta')->nullable();
            $table->dateTime('created_at')->nullable();

            $table->index(['structure_id'], 'idx_wse_structure_id');
            $table->unique(['structure_id', 'type_key'], 'idx_wse_structure_type_key');
        });

        Schema::create('zp_work_structure_relationships', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('structure_id');
            $table->unsignedBigInteger('from_element_id');
            $table->unsignedBigInteger('to_element_id');
            $table->string('relationship_type', 50);
            $table->text('description')->nullable();
            $table->json('meta')->nullable();

            $table->index(['structure_id'], 'idx_wsr_structure_id');
        });

        Schema::create('zp_work_structure_mappings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('source_structure_id');
            $table->unsignedBigInteger('source_element_id');
            $table->unsignedBigInteger('target_structure_id');
            $table->unsignedBigInteger('target_element_id');
            $table->string('mapping_type', 50)->default('generates');
            $table->json('meta')->nullable();

            $table->index(['source_structure_id', 'target_structure_id'], 'idx_wsm_source_target');
            // Enforce the same idempotency key StructureRegistry::registerMappings()
            // checks in application code, so a race can't insert a duplicate mapping
            // for the same source element → target structure.
            $table->unique(['source_structure_id', 'source_element_id', 'target_structure_id'], 'idx_wsm_unique_mapping');
        });
    }

    /**
     * Create Julianna's independent identity store. No foreign keys are used so
     * the tables remain compatible with the legacy schema's cross-database
     * conventions and its existing account-deletion behavior.
     */
    private function createJuliannaAuthTables(): void
    {
        Schema::create('julianna_auth_accounts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('email_normalized', 254);
            $table->string('display_name', 200);
            $table->string('password_hash', 255);
            $table->string('state', 32);
            $table->dateTime('email_verified_at')->nullable();
            $table->dateTime('approved_at')->nullable();
            $table->unsignedBigInteger('approved_by_user_id')->nullable();
            $table->dateTime('rejected_at')->nullable();
            $table->dateTime('disabled_at')->nullable();
            $table->dateTime('mfa_confirmed_at')->nullable();
            $table->dateTime('password_changed_at')->nullable();
            $table->unsignedInteger('session_version')->default(1);
            $table->dateTime('created_at');
            $table->dateTime('updated_at');

            $table->unique(['email_normalized'], 'jau_accounts_email_unique');
            $table->unique(['user_id'], 'jau_accounts_user_unique');
            $table->index(['state', 'created_at'], 'jau_accounts_state_created');
        });

        Schema::create('julianna_auth_tokens', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_id');
            $table->string('purpose', 32);
            $table->char('token_hash', 64);
            $table->dateTime('expires_at');
            $table->dateTime('consumed_at')->nullable();
            $table->dateTime('created_at');

            $table->unique(['token_hash'], 'jau_tokens_hash_unique');
            $table->index(['account_id', 'purpose', 'consumed_at'], 'jau_tokens_account_purpose');
            $table->index(['expires_at'], 'jau_tokens_expires');
        });

        Schema::create('julianna_auth_mfa', function (Blueprint $table) {
            $table->unsignedBigInteger('account_id')->primary();
            $table->text('encrypted_secret');
            $table->dateTime('confirmed_at')->nullable();
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
        });

        Schema::create('julianna_auth_recovery_codes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_id');
            $table->string('code_hash', 255);
            $table->dateTime('consumed_at')->nullable();
            $table->dateTime('created_at');

            $table->index(['account_id', 'consumed_at'], 'jau_recovery_account_unused');
        });

        Schema::create('julianna_auth_audit_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_id')->nullable();
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->string('event', 64);
            $table->char('subject_identifier_hash', 64)->nullable();
            $table->char('ip_hash', 64)->nullable();
            $table->text('context')->nullable();
            $table->dateTime('created_at');

            $table->index(['account_id', 'created_at'], 'jau_audit_account_created');
            $table->index(['event', 'created_at'], 'jau_audit_event_created');
        });

        Schema::create('julianna_auth_sessions', function (Blueprint $table) {
            $table->char('session_hash', 64)->primary();
            $table->unsignedBigInteger('account_id');
            $table->unsignedInteger('session_version');
            $table->dateTime('expires_at');
            $table->dateTime('revoked_at')->nullable();
            $table->dateTime('created_at');
            $table->dateTime('last_seen_at');

            $table->index(['account_id', 'revoked_at'], 'jau_sessions_account_active');
            $table->index(['expires_at'], 'jau_sessions_expires');
        });
    }

    /**
     * Create the Idea Room tables for fresh installations and upgrades. The
     * guards let an interrupted upgrade safely create whichever table is still
     * missing when it runs again.
     */
    public function createIdeaRoomTables(): void
    {
        if (! Schema::hasTable('julianna_idea_rooms')) {
            Schema::create('julianna_idea_rooms', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('owner_user_id');
                $table->unsignedBigInteger('project_id')->nullable();
                $table->string('status', 32);
                $table->string('title', 255);
                $table->json('plan_json');
                $table->unsignedBigInteger('approved_project_id')->nullable();
                $table->unsignedBigInteger('approved_goal_id')->nullable();
                $table->json('approval_result_json')->nullable();
                $table->dateTime('created_at');
                $table->dateTime('updated_at');
                $table->dateTime('approved_at')->nullable();

                $table->index(['owner_user_id', 'status', 'updated_at'], 'jir_owner_status_updated');
                $table->index(['project_id', 'status'], 'jir_project_status');
                $table->unique(['approved_goal_id'], 'jir_approved_goal_unique');
            });
        }

        if (! Schema::hasTable('julianna_idea_messages')) {
            Schema::create('julianna_idea_messages', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('room_id');
                $table->string('role', 16);
                $table->longText('content');
                $table->dateTime('created_at');

                $table->index(['room_id', 'id'], 'jim_room_id');
                $table->foreign('room_id', 'jim_room_fk')
                    ->references('id')->on('julianna_idea_rooms')->cascadeOnDelete();
            });
        }
    }

    public function createIdeaRoomChatTables(): void
    {
        if (! Schema::hasColumn('julianna_idea_messages', 'metadata_json')) {
            Schema::table('julianna_idea_messages', function (Blueprint $table): void {
                $table->json('metadata_json')->nullable();
            });
        }

        if (! Schema::hasTable('julianna_idea_events')) {
            Schema::create('julianna_idea_events', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('room_id');
                $table->string('event_name', 64);
                $table->json('payload_json');
                $table->dateTime('created_at');
                $table->index(['room_id', 'id'], 'jie_room_id');
                $table->foreign('room_id', 'jie_room_fk')->references('id')->on('julianna_idea_rooms')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('julianna_idea_actions')) {
            Schema::create('julianna_idea_actions', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('room_id');
                $table->string('tool_call_id', 255);
                $table->string('tool_name', 128);
                $table->json('arguments_json');
                $table->string('status', 32);
                $table->boolean('destructive');
                $table->string('idempotency_key', 64)->unique();
                $table->json('result_json')->nullable();
                $table->dateTime('expires_at');
                $table->dateTime('created_at');
                $table->dateTime('updated_at');
                $table->unique(['room_id', 'tool_call_id'], 'jia_room_call_unique');
                $table->index(['room_id', 'status'], 'jia_room_status');
                $table->foreign('room_id', 'jia_room_fk')->references('id')->on('julianna_idea_rooms')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('julianna_idea_generations')) {
            Schema::create('julianna_idea_generations', function (Blueprint $table): void {
                $table->unsignedBigInteger('room_id')->primary();
                $table->string('status', 32);
                $table->boolean('cancel_requested')->default(false);
                $table->unsignedTinyInteger('tool_turns')->default(0);
                $table->dateTime('updated_at');
                $table->foreign('room_id', 'jig_room_fk')->references('id')->on('julianna_idea_rooms')->cascadeOnDelete();
            });
        }
    }

    public function createIdeaRoomGraphTables(): void
    {
        if (! Schema::hasColumn('julianna_idea_rooms', 'graph_version')) {
            Schema::table('julianna_idea_rooms', function (Blueprint $table): void {
                $table->unsignedInteger('graph_version')->default(0);
            });
        }
        if (! Schema::hasColumn('julianna_idea_rooms', 'mode')) {
            Schema::table('julianna_idea_rooms', function (Blueprint $table): void {
                $table->string('mode', 16)->default('explore');
            });
        }
        if (! Schema::hasTable('julianna_idea_graph_nodes')) {
            Schema::create('julianna_idea_graph_nodes', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('room_id');
                $table->string('type', 32);
                $table->string('title', 255);
                $table->text('content');
                $table->decimal('position_x', 10, 2);
                $table->decimal('position_y', 10, 2);
                $table->json('metadata_json');
                $table->unsignedBigInteger('author_user_id');
                $table->dateTime('created_at');
                $table->dateTime('updated_at');
                $table->index(['room_id', 'id'], 'jign_room_id');
                $table->foreign('room_id', 'jign_room_fk')->references('id')->on('julianna_idea_rooms')->cascadeOnDelete();
            });
        }
        if (! Schema::hasTable('julianna_idea_graph_links')) {
            Schema::create('julianna_idea_graph_links', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('room_id');
                $table->unsignedBigInteger('source_node_id');
                $table->unsignedBigInteger('target_node_id');
                $table->string('type', 32);
                $table->dateTime('created_at');
                $table->dateTime('updated_at');
                $table->index(['room_id', 'id'], 'jigl_room_id');
                $table->index(['source_node_id', 'target_node_id'], 'jigl_endpoints');
                $table->foreign('room_id', 'jigl_room_fk')->references('id')->on('julianna_idea_rooms')->cascadeOnDelete();
                $table->foreign('source_node_id', 'jigl_source_fk')->references('id')->on('julianna_idea_graph_nodes')->cascadeOnDelete();
                $table->foreign('target_node_id', 'jigl_target_fk')->references('id')->on('julianna_idea_graph_nodes')->cascadeOnDelete();
            });
        }
        if (! Schema::hasTable('julianna_idea_sources')) {
            Schema::create('julianna_idea_sources', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('room_id');
                $table->string('url', 2048);
                $table->string('title', 255);
                $table->text('snippet');
                $table->string('domain', 255);
                $table->string('provider', 64);
                $table->string('query', 500);
                $table->dateTime('created_at');
                $table->index(['room_id', 'id'], 'jis_room_id');
                $table->foreign('room_id', 'jis_room_fk')->references('id')->on('julianna_idea_rooms')->cascadeOnDelete();
            });
        }
        if (! Schema::hasTable('julianna_idea_proposals')) {
            Schema::create('julianna_idea_proposals', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('room_id');
                $table->unsignedBigInteger('author_user_id');
                $table->string('status', 24);
                $table->unsignedInteger('graph_version');
                $table->json('patch_json');
                $table->json('inspiration_cards_json');
                $table->dateTime('created_at');
                $table->dateTime('updated_at');
                $table->index(['room_id', 'status', 'id'], 'jip_room_status');
                $table->foreign('room_id', 'jip_room_fk')->references('id')->on('julianna_idea_rooms')->cascadeOnDelete();
            });
        }
    }

    /** Versioned, recoverable review state for agent-authored Idea Room changes. */
    public function createIdeaRoomMcpColumns(): void
    {
        if (! Schema::hasColumn('julianna_idea_rooms', 'plan_version')) {
            Schema::table('julianna_idea_rooms', static function (Blueprint $table): void {
                $table->unsignedInteger('plan_version')->default(0);
            });
        }
        if (! Schema::hasColumn('julianna_idea_graph_nodes', 'deleted_at')) {
            Schema::table('julianna_idea_graph_nodes', static function (Blueprint $table): void {
                $table->dateTime('deleted_at')->nullable();
            });
        }
        if (! Schema::hasColumn('julianna_idea_graph_links', 'deleted_at')) {
            Schema::table('julianna_idea_graph_links', static function (Blueprint $table): void {
                $table->dateTime('deleted_at')->nullable();
            });
        }
        if (! Schema::hasColumn('julianna_idea_graph_links', 'deleted_with_node')) {
            Schema::table('julianna_idea_graph_links', static function (Blueprint $table): void {
                $table->boolean('deleted_with_node')->default(false);
            });
        }
        if (! Schema::hasColumn('julianna_idea_proposals', 'origin')) {
            Schema::table('julianna_idea_proposals', static function (Blueprint $table): void {
                $table->string('origin', 16)->default('chat');
            });
        }
        if (! Schema::hasColumn('julianna_idea_proposals', 'summary')) {
            Schema::table('julianna_idea_proposals', static function (Blueprint $table): void {
                $table->string('summary', 255)->default('');
            });
        }
        if (! Schema::hasColumn('julianna_idea_proposals', 'plan_version')) {
            Schema::table('julianna_idea_proposals', static function (Blueprint $table): void {
                $table->unsignedInteger('plan_version')->default(0);
            });
        }
    }

    /** Durable, room-scoped snapshots used for canvas and plan undo. */
    public function createIdeaRoomHistoryTable(): void
    {
        if (Schema::hasTable('julianna_idea_history')) {
            return;
        }

        Schema::create('julianna_idea_history', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('room_id');
            $table->unsignedBigInteger('author_user_id');
            $table->string('origin', 16);
            $table->string('summary', 255);
            $table->unsignedInteger('graph_version');
            $table->unsignedInteger('plan_version');
            $table->json('graph_json');
            $table->json('plan_json');
            $table->dateTime('created_at');
            $table->index(['room_id', 'id'], 'jih_room_id');
            $table->foreign('room_id', 'jih_room_fk')->references('id')->on('julianna_idea_rooms')->cascadeOnDelete();
        });
    }

    /**
     * Per-project agent controls, idempotent background review runs, and an
     * append-only account of actions visible in the command center. These
     * tables deliberately do not cascade with zp_projects: an audit trail must
     * not disappear when a project is deleted.
     */
    public function createProjectAgentTables(): void
    {
        if (! Schema::hasTable('julianna_agent_projects')) {
            Schema::create('julianna_agent_projects', static function (Blueprint $table): void {
                $table->unsignedBigInteger('project_id')->primary();
                $table->boolean('enabled')->default(false);
                $table->boolean('paused')->default(false);
                $table->unsignedBigInteger('enabled_by_user_id')->nullable();
                $table->dateTime('created_at');
                $table->dateTime('updated_at');
                $table->index(['enabled', 'paused'], 'jap_active');
            });
        }

        if (! Schema::hasTable('julianna_agent_runs')) {
            Schema::create('julianna_agent_runs', static function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('project_id');
                $table->unsignedBigInteger('actor_user_id');
                $table->string('trigger', 32);
                $table->char('idempotency_key', 64);
                $table->string('status', 32);
                $table->string('summary', 255)->nullable();
                $table->dateTime('created_at');
                $table->dateTime('started_at')->nullable();
                $table->dateTime('heartbeat_at')->nullable();
                $table->dateTime('finished_at')->nullable();
                $table->unique('idempotency_key', 'jar_key_unique');
                $table->index(['project_id', 'id'], 'jar_project_id');
                $table->index(['status', 'created_at'], 'jar_status_created');
            });
        }

        if (! Schema::hasTable('julianna_agent_activities')) {
            Schema::create('julianna_agent_activities', static function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('project_id');
                $table->unsignedBigInteger('actor_user_id');
                $table->unsignedBigInteger('run_id')->nullable();
                $table->string('action', 128);
                $table->text('rationale');
                $table->text('outcome');
                $table->string('status', 24);
                $table->json('recovery_json')->nullable();
                $table->char('idempotency_key', 64)->nullable();
                $table->dateTime('created_at');
                $table->dateTime('undone_at')->nullable();
                $table->unsignedBigInteger('undone_by_user_id')->nullable();
                $table->unique('idempotency_key', 'jaa_key_unique');
                $table->index(['project_id', 'id'], 'jaa_project_id');
                $table->index(['run_id', 'id'], 'jaa_run_id');
            });
        }
    }

    /**
     * Julianna-owned Whiteboards. Scenes contain element/app-state metadata;
     * binary files are stored once in the immutable asset table and referenced
     * by file ID from each durable scene revision.
     */
    public function createWhiteboardTables(): void
    {
        if (! Schema::hasTable('julianna_whiteboards')) {
            Schema::create('julianna_whiteboards', static function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('project_id');
                $table->string('title', 160);
                $table->unsignedInteger('revision')->default(0);
                $table->json('scene_json');
                $table->unsignedBigInteger('created_by_user_id');
                $table->unsignedBigInteger('updated_by_user_id');
                $table->dateTime('created_at');
                $table->dateTime('updated_at');
                $table->index(['project_id', 'id'], 'jwb_project_id');
            });
        }

        if (! Schema::hasTable('julianna_whiteboard_revisions')) {
            Schema::create('julianna_whiteboard_revisions', static function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('board_id');
                $table->unsignedInteger('revision');
                $table->json('scene_json');
                $table->unsignedBigInteger('author_user_id');
                $table->dateTime('created_at');
                $table->unique(['board_id', 'revision'], 'jwbr_board_revision');
                $table->index(['board_id', 'id'], 'jwbr_board_id');
            });
        }

        if (! Schema::hasTable('julianna_whiteboard_assets')) {
            Schema::create('julianna_whiteboard_assets', static function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('board_id');
                $table->string('file_id', 128);
                $table->string('mime_type', 80);
                $table->longText('data_url');
                $table->unsignedBigInteger('byte_size');
                $table->dateTime('created_at');
                $table->unique(['board_id', 'file_id'], 'jwba_board_file');
                $table->index(['board_id', 'id'], 'jwba_board_id');
            });
        }
    }

    /** Agent records survive project deletion so actions remain auditable. */
    public function createAgentHarnessTables(): void
    {
        if (! Schema::hasTable('julianna_agent_conversations')) {
            Schema::create('julianna_agent_conversations', static function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('owner_user_id');
                $table->unsignedBigInteger('project_id')->nullable();
                $table->string('title', 255);
                $table->string('state', 24)->default('idle');
                $table->string('page_path', 512)->nullable();
                $table->dateTime('created_at');
                $table->dateTime('updated_at');
                $table->index(['owner_user_id', 'id'], 'jac_owner_id');
                $table->index(['project_id', 'id'], 'jac_project_id');
            });
        }

        if (! Schema::hasTable('julianna_agent_turns')) {
            Schema::create('julianna_agent_turns', static function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('conversation_id');
                $table->string('role', 16);
                $table->longText('content');
                $table->json('metadata_json')->nullable();
                $table->char('client_key', 64)->nullable();
                $table->dateTime('created_at');
                $table->unique(['conversation_id', 'client_key'], 'jat_client_key');
                $table->index(['conversation_id', 'id'], 'jat_conversation_id');
            });
        }

        if (! Schema::hasTable('julianna_agent_tool_receipts')) {
            Schema::create('julianna_agent_tool_receipts', static function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('conversation_id');
                $table->unsignedBigInteger('actor_user_id');
                $table->unsignedBigInteger('project_id')->nullable();
                $table->string('tool_call_id', 256);
                $table->string('tool_name', 64);
                $table->char('arguments_hash', 64);
                $table->string('effect', 32);
                $table->string('status', 24);
                $table->json('result_json')->nullable();
                $table->dateTime('created_at');
                $table->dateTime('finished_at')->nullable();
                $table->unique(['conversation_id', 'tool_call_id'], 'jatr_call_unique');
                $table->index(['project_id', 'id'], 'jatr_project_id');
            });
        }

        if (! Schema::hasTable('julianna_agent_drafts')) {
            Schema::create('julianna_agent_drafts', static function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('conversation_id');
                $table->unsignedBigInteger('project_id');
                $table->unsignedBigInteger('actor_user_id');
                $table->string('tool_name', 64);
                $table->json('arguments_json');
                $table->text('reason');
                $table->string('status', 24)->default('draft');
                $table->dateTime('created_at');
                $table->dateTime('updated_at');
                $table->index(['project_id', 'status', 'id'], 'jad_project_status');
            });
        }

        if (! Schema::hasTable('julianna_agent_questions')) {
            Schema::create('julianna_agent_questions', static function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('conversation_id');
                $table->unsignedBigInteger('project_id')->nullable();
                $table->longText('question');
                $table->string('status', 24)->default('open');
                $table->dateTime('created_at');
                $table->dateTime('resolved_at')->nullable();
                $table->index(['project_id', 'status', 'id'], 'jaq_project_status');
            });
        }
    }

    /** Cross-run claims prevent daily and event reviews from repeating an action. */
    public function createAgentActionClaimsTable(): void
    {
        if (! Schema::hasTable('julianna_agent_action_claims')) {
            Schema::create('julianna_agent_action_claims', static function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('project_id');
                $table->unsignedBigInteger('actor_user_id');
                $table->string('tool_name', 64);
                $table->char('action_key', 64)->unique();
                $table->string('status', 24);
                $table->dateTime('created_at');
                $table->dateTime('finished_at')->nullable();
                $table->index(['project_id', 'id'], 'jaac_project_id');
            });
        }
    }

    /** One encrypted, installation-wide AI connector owned by Julianna. */
    public function createAgentAiSettingsTable(): void
    {
        if (Schema::hasTable('julianna_agent_ai_settings')) {
            return;
        }

        Schema::create('julianna_agent_ai_settings', static function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->string('provider', 32)->nullable();
            $table->string('model', 128)->nullable();
            $table->text('encrypted_api_key')->nullable();
            $table->unsignedBigInteger('updated_by_user_id');
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
        });
    }

    /** Separate encrypted web-search credential so configuring it cannot alter the AI provider. */
    public function createAgentWebSearchSettingsTable(): void
    {
        if (Schema::hasTable('julianna_agent_web_search_settings')) {
            return;
        }

        Schema::create('julianna_agent_web_search_settings', static function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->boolean('enabled');
            $table->text('encrypted_api_key')->nullable();
            $table->unsignedBigInteger('updated_by_user_id');
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
        });
    }

    /** Existing queued-run tables gain a worker heartbeat without replaying work. */
    public function addProjectAgentRunHeartbeat(): void
    {
        if (Schema::hasTable('julianna_agent_runs') && ! Schema::hasColumn('julianna_agent_runs', 'heartbeat_at')) {
            Schema::table('julianna_agent_runs', static function (Blueprint $table): void {
                $table->dateTime('heartbeat_at')->nullable()->after('started_at');
            });
        }
    }
}
