<?php

namespace Unit\app\Domain\Install;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Grammars\MySqlGrammar;
use Illuminate\Support\Facades\Schema;
use Leantime\Core\Configuration\AppSettings;
use Leantime\Domain\Install\Repositories\Install;
use Leantime\Domain\Install\Services\SchemaBuilder;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Unit\TestCase;

class IdeaRoomSchemaTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function test_creates_room_and_message_tables_with_resumable_and_approval_fields(): void
    {
        $blueprints = [];
        $connection = new Connection(null);
        $connection->setSchemaGrammar(new MySqlGrammar($connection));
        $schema = Mockery::mock();
        $schema->shouldReceive('hasTable')->with('julianna_idea_rooms')->once()->andReturn(false);
        $schema->shouldReceive('hasTable')->with('julianna_idea_messages')->once()->andReturn(false);
        $schema->shouldReceive('create')->twice()->andReturnUsing(
            function (string $name, callable $definition) use (&$blueprints, $connection): void {
                $blueprint = new Blueprint($connection, $name);
                $definition($blueprint);
                $blueprints[$name] = $blueprint;
            }
        );
        Schema::swap($schema);

        (new SchemaBuilder(new AppSettings))->createIdeaRoomTables();

        $this->assertSame(
            ['id', 'owner_user_id', 'project_id', 'status', 'title', 'plan_json', 'approved_project_id', 'approved_goal_id', 'approval_result_json', 'created_at', 'updated_at', 'approved_at'],
            array_map(fn ($column) => $column->name, $blueprints['julianna_idea_rooms']->getColumns())
        );
        $this->assertSame(
            ['id', 'room_id', 'role', 'content', 'created_at'],
            array_map(fn ($column) => $column->name, $blueprints['julianna_idea_messages']->getColumns())
        );
        $this->assertSame('json', $blueprints['julianna_idea_rooms']->getColumns()[5]->type);
        $this->assertSame('longText', $blueprints['julianna_idea_messages']->getColumns()[3]->type);
        $this->assertContains('foreign', array_map(fn ($command) => $command->name, $blueprints['julianna_idea_messages']->getCommands()));
    }

    public function test_rerun_skips_tables_that_already_exist(): void
    {
        $schema = Mockery::mock();
        $schema->shouldReceive('hasTable')->with('julianna_idea_rooms')->once()->andReturn(true);
        $schema->shouldReceive('hasTable')->with('julianna_idea_messages')->once()->andReturn(true);
        $schema->shouldNotReceive('create');
        Schema::swap($schema);

        (new SchemaBuilder(new AppSettings))->createIdeaRoomTables();
    }

    public function test_creates_chat_event_action_and_generation_tables(): void
    {
        $blueprints = [];
        $connection = new Connection(null);
        $connection->setSchemaGrammar(new MySqlGrammar($connection));
        $schema = Mockery::mock();
        $schema->shouldReceive('hasColumn')->with('julianna_idea_messages', 'metadata_json')->once()->andReturn(false);
        $schema->shouldReceive('table')->with('julianna_idea_messages', Mockery::type('callable'))->once();
        foreach (['julianna_idea_events', 'julianna_idea_actions', 'julianna_idea_generations'] as $name) {
            $schema->shouldReceive('hasTable')->with($name)->once()->andReturn(false);
        }
        $schema->shouldReceive('create')->times(3)->andReturnUsing(
            function (string $name, callable $definition) use (&$blueprints, $connection): void {
                $blueprint = new Blueprint($connection, $name);
                $definition($blueprint);
                $blueprints[$name] = $blueprint;
            }
        );
        Schema::swap($schema);

        (new SchemaBuilder(new AppSettings))->createIdeaRoomChatTables();

        $this->assertArrayHasKey('julianna_idea_events', $blueprints);
        $this->assertArrayHasKey('julianna_idea_actions', $blueprints);
        $this->assertArrayHasKey('julianna_idea_generations', $blueprints);
        $this->assertContains('idempotency_key', array_map(fn ($column) => $column->name, $blueprints['julianna_idea_actions']->getColumns()));
        $this->assertContains('tool_turns', array_map(fn ($column) => $column->name, $blueprints['julianna_idea_generations']->getColumns()));
    }

    public function test_existing_installations_run_the_registered_chat_upgrade(): void
    {
        $builder = Mockery::mock(SchemaBuilder::class);
        $builder->shouldReceive('createIdeaRoomChatTables')->once();
        app()->instance(SchemaBuilder::class, $builder);

        $install = (new \ReflectionClass(Install::class))->newInstanceWithoutConstructor();
        $updates = (new \ReflectionProperty(Install::class, 'dbUpdates'))->getValue($install);

        $this->assertSame('3.5.38', (new AppSettings)->dbVersion);
        $this->assertContains(30535, $updates);
        $this->assertContains(30536, $updates);
        $this->assertContains(30537, $updates);
        $this->assertContains(30538, $updates);
        $this->assertContains(30527, $updates);
        $this->assertContains(30528, $updates);
        $this->assertTrue($install->update_sql_30528());
    }

    public function test_ai_settings_upgrade_is_registered_and_idempotent(): void
    {
        $builder = Mockery::mock(SchemaBuilder::class);
        $builder->shouldReceive('createAgentAiSettingsTable')->twice();
        app()->instance(SchemaBuilder::class, $builder);

        $install = (new \ReflectionClass(Install::class))->newInstanceWithoutConstructor();
        $this->assertContains(30537, (new \ReflectionProperty(Install::class, 'dbUpdates'))->getValue($install));
        $this->assertTrue($install->update_sql_30537());
        $this->assertTrue($install->update_sql_30537());
    }

    public function test_graph_upgrade_creates_version_columns_and_all_four_tables(): void
    {
        $blueprints = [];
        $connection = new Connection(null);
        $connection->setSchemaGrammar(new MySqlGrammar($connection));
        $schema = Mockery::mock();
        $schema->shouldReceive('hasColumn')->with('julianna_idea_rooms', 'graph_version')->once()->andReturn(false);
        $schema->shouldReceive('hasColumn')->with('julianna_idea_rooms', 'mode')->once()->andReturn(false);
        $schema->shouldReceive('table')->with('julianna_idea_rooms', Mockery::type('callable'))->twice();
        foreach (['julianna_idea_graph_nodes', 'julianna_idea_graph_links', 'julianna_idea_sources', 'julianna_idea_proposals'] as $name) {
            $schema->shouldReceive('hasTable')->with($name)->once()->andReturn(false);
        }
        $schema->shouldReceive('create')->times(4)->andReturnUsing(
            function (string $name, callable $definition) use (&$blueprints, $connection): void {
                $blueprint = new Blueprint($connection, $name);
                $definition($blueprint);
                $blueprints[$name] = $blueprint;
            }
        );
        Schema::swap($schema);

        (new SchemaBuilder(new AppSettings))->createIdeaRoomGraphTables();

        $this->assertCount(4, $blueprints);
        $this->assertContains('metadata_json', array_map(fn ($column) => $column->name, $blueprints['julianna_idea_graph_nodes']->getColumns()));
        $this->assertContains('graph_version', array_map(fn ($column) => $column->name, $blueprints['julianna_idea_proposals']->getColumns()));
        $install = (new \ReflectionClass(Install::class))->newInstanceWithoutConstructor();
        $this->assertContains(30529, (new \ReflectionProperty(Install::class, 'dbUpdates'))->getValue($install));
    }

    public function test_mcp_upgrade_is_registered_and_adds_review_and_restore_columns(): void
    {
        $builder = Mockery::mock(SchemaBuilder::class);
        $builder->shouldReceive('createIdeaRoomMcpColumns')->once();
        app()->instance(SchemaBuilder::class, $builder);

        $install = (new \ReflectionClass(Install::class))->newInstanceWithoutConstructor();
        $this->assertContains(30530, (new \ReflectionProperty(Install::class, 'dbUpdates'))->getValue($install));
        $this->assertTrue($install->update_sql_30530());
    }

    public function test_history_upgrade_creates_room_scoped_snapshots_once(): void
    {
        $blueprints = [];
        $connection = new Connection(null);
        $connection->setSchemaGrammar(new MySqlGrammar($connection));
        $schema = Mockery::mock();
        $schema->shouldReceive('hasTable')->with('julianna_idea_history')->twice()->andReturn(false, true);
        $schema->shouldReceive('create')->with('julianna_idea_history', Mockery::type('callable'))->once()
            ->andReturnUsing(function (string $name, callable $definition) use (&$blueprints, $connection): void {
                $blueprint = new Blueprint($connection, $name);
                $definition($blueprint);
                $blueprints[$name] = $blueprint;
            });
        Schema::swap($schema);

        $builder = new SchemaBuilder(new AppSettings);
        $builder->createIdeaRoomHistoryTable();
        $builder->createIdeaRoomHistoryTable();

        $columns = array_map(fn ($column) => $column->name, $blueprints['julianna_idea_history']->getColumns());
        $this->assertSame([
            'id', 'room_id', 'author_user_id', 'origin', 'summary', 'graph_version',
            'plan_version', 'graph_json', 'plan_json', 'created_at',
        ], $columns);
        $this->assertContains('foreign', array_map(fn ($command) => $command->name, $blueprints['julianna_idea_history']->getCommands()));

        $builderMock = Mockery::mock(SchemaBuilder::class);
        $builderMock->shouldReceive('createIdeaRoomHistoryTable')->once();
        app()->instance(SchemaBuilder::class, $builderMock);
        $install = (new \ReflectionClass(Install::class))->newInstanceWithoutConstructor();
        $this->assertContains(30531, (new \ReflectionProperty(Install::class, 'dbUpdates'))->getValue($install));
        $this->assertTrue($install->update_sql_30531());
    }
}
