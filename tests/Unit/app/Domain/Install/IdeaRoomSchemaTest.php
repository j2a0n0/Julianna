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

    public function test_existing_installations_run_the_registered_30527_upgrade(): void
    {
        $builder = Mockery::mock(SchemaBuilder::class);
        $builder->shouldReceive('createIdeaRoomTables')->once();
        app()->instance(SchemaBuilder::class, $builder);

        $install = (new \ReflectionClass(Install::class))->newInstanceWithoutConstructor();
        $updates = (new \ReflectionProperty(Install::class, 'dbUpdates'))->getValue($install);

        $this->assertSame('3.5.27', (new AppSettings)->dbVersion);
        $this->assertContains(30527, $updates);
        $this->assertTrue($install->update_sql_30527());
    }
}
