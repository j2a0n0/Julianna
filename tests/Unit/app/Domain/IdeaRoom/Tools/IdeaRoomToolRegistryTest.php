<?php

declare(strict_types=1);

namespace Unit\app\Domain\IdeaRoom\Tools;

use InvalidArgumentException;
use Leantime\Core\Auth\Permissions\PermissionService;
use Leantime\Core\Exceptions\AuthorizationException;
use Leantime\Domain\Calendar\Services\Calendar;
use Leantime\Domain\Goalcanvas\Services\Goalcanvas;
use Leantime\Domain\IdeaRoom\Tools\IdeaRoomToolRegistry;
use Leantime\Domain\Projects\Permissions\ProjectsPermissions;
use Leantime\Domain\Projects\Services\Projects;
use Leantime\Domain\Tickets\Services\Tickets;
use PHPUnit\Framework\TestCase;

final class IdeaRoomToolRegistryTest extends TestCase
{
    public function test_all_definitions_are_explicitly_classified_and_bounded(): void
    {
        $registry = $this->registry();
        $definitions = $registry->definitions();

        self::assertGreaterThanOrEqual(20, count($definitions));
        foreach ($definitions as $definition) {
            self::assertSame('object', $definition['input_schema']['type']);
            self::assertFalse($definition['input_schema']['additionalProperties']);
            self::assertIsBool($definition['write']);
            self::assertIsBool($definition['destructive']);
            self::assertSame(
                ['write' => $definition['write'], 'destructive' => $definition['destructive']],
                $registry->classify($definition['name'])
            );
            if ($definition['destructive']) {
                self::assertTrue($definition['write']);
            }
        }

        self::assertSame(['write' => false, 'destructive' => false], $registry->classify('findTasks'));
        self::assertSame(['write' => true, 'destructive' => false], $registry->classify('addTask'));
        self::assertSame(['write' => true, 'destructive' => true], $registry->classify('deleteEvent'));
        self::assertSame(['write' => true, 'destructive' => false], $registry->classify('bulkAddTasks'));
        self::assertSame(['write' => true, 'destructive' => false], $registry->classify('bulkEditTasks'));
    }

    public function test_unknown_tool_is_never_executed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->registry()->execute('shell', ['command' => 'whoami'], true);
    }

    public function test_unknown_fields_are_rejected_including_nested_project_reassignment(): void
    {
        $registry = $this->registry();
        try {
            $registry->validate('addTask', ['projectId' => 1, 'headline' => 'Hello', 'userId' => 999]);
            self::fail('Unexpected userId accepted.');
        } catch (InvalidArgumentException) {
            self::assertTrue(true);
        }

        $this->expectException(InvalidArgumentException::class);
        $registry->validate('editTask', ['id' => 1, 'params' => ['projectId' => 999]]);
    }

    public function test_cross_project_search_is_bounded_and_typed(): void
    {
        $registry = $this->registry();
        self::assertSame(['projectIds' => [1, 2], 'limit' => 25], $registry->validate('findTasks', ['projectIds' => [1, 2], 'limit' => 25]));

        $this->expectException(InvalidArgumentException::class);
        $registry->validate('findTasks', ['projectIds' => range(1, 11)]);
    }

    public function test_bulk_writes_are_bounded_and_cannot_reassign_project_during_edit(): void
    {
        $registry = $this->registry();
        $tasks = array_fill(0, 10, ['projectId' => 1, 'headline' => 'Next action']);
        self::assertSame(['tasks' => $tasks], $registry->validate('bulkAddTasks', ['tasks' => $tasks]));
        try {
            $registry->validate('bulkAddTasks', ['tasks' => [...$tasks, $tasks[0]]]);
            self::fail('Eleven tasks were accepted.');
        } catch (InvalidArgumentException) {
            self::assertTrue(true);
        }

        $this->expectException(InvalidArgumentException::class);
        $registry->validate('bulkEditTasks', ['updates' => [['id' => 1, 'params' => ['projectId' => 2]]]]);
    }

    public function test_a_write_cannot_execute_without_confirmation(): void
    {
        $this->expectException(AuthorizationException::class);
        $this->registry()->execute('addTask', ['projectId' => 1, 'headline' => 'New action']);
    }

    public function test_autonomous_write_must_target_only_its_enabled_project(): void
    {
        $registry = $this->registry();
        $registry->assertProjectScope('addTask', ['projectId' => 3, 'headline' => 'New action'], 3);
        $registry->assertProjectScope('bulkAddTasks', ['tasks' => [
            ['projectId' => 3, 'headline' => 'One'],
            ['projectId' => 3, 'headline' => 'Two'],
        ]], 3);

        foreach ([
            ['addTask', ['projectId' => 4, 'headline' => 'Other project']],
            ['bulkAddTasks', ['tasks' => [['projectId' => 3, 'headline' => 'One'], ['projectId' => 4, 'headline' => 'Two']]]],
            ['addProject', ['name' => 'Unscoped project']],
            ['addEvent', ['eventTitle' => 'Unscoped event', 'dateFrom' => '2026-10-01', 'dateTo' => '2026-10-02']],
        ] as [$name, $arguments]) {
            try {
                $registry->assertProjectScope($name, $arguments, 3);
                self::fail("Unexpected autonomous scope for {$name}.");
            } catch (AuthorizationException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_read_schema_rejects_malformed_arguments(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->registry()->validate('getProject', ['projectId' => '1']);
    }

    public function test_cross_project_read_is_denied_before_tool_dispatch(): void
    {
        $permissions = $this->createMock(PermissionService::class);
        $permissions->expects(self::once())->method('authorize')
            ->with(ProjectsPermissions::VIEW, 99)->willThrowException(new AuthorizationException);
        $projects = $this->createMock(Projects::class);
        $projects->expects(self::never())->method('getProject');
        $registry = new IdeaRoomToolRegistry($permissions, $projects,
            $this->createMock(Tickets::class), $this->createMock(Goalcanvas::class), $this->createMock(Calendar::class));

        $this->expectException(AuthorizationException::class);
        (new \ReflectionMethod($registry, 'authorizeArguments'))->invoke($registry, 'getProject', ['projectId' => 99]);
    }

    public function test_deleted_project_is_denied_even_if_permission_cache_allows_it(): void
    {
        $permissions = $this->createMock(PermissionService::class);
        $permissions->expects(self::once())->method('authorize')->with(ProjectsPermissions::VIEW, 3);
        $projects = $this->createMock(Projects::class);
        $projects->expects(self::once())->method('getProject')->with(3)->willReturn(false);
        $registry = new IdeaRoomToolRegistry($permissions, $projects,
            $this->createMock(Tickets::class), $this->createMock(Goalcanvas::class), $this->createMock(Calendar::class));

        $this->expectException(AuthorizationException::class);
        (new \ReflectionMethod($registry, 'authorizeArguments'))->invoke($registry, 'getProject', ['projectId' => 3]);
    }

    private function registry(): IdeaRoomToolRegistry
    {
        return new IdeaRoomToolRegistry(
            $this->createMock(PermissionService::class),
            $this->createMock(Projects::class),
            $this->createMock(Tickets::class),
            $this->createMock(Goalcanvas::class),
            $this->createMock(Calendar::class),
        );
    }
}
