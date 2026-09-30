<?php

declare(strict_types=1);

namespace Unit\app\Domain\Mcp\Services;

use InvalidArgumentException;
use Leantime\Core\Auth\Permissions\PermissionService;
use Leantime\Domain\Calendar\Services\Calendar;
use Leantime\Domain\Goalcanvas\Services\Goalcanvas;
use Leantime\Domain\Mcp\Server\CatalogMcpTool;
use Leantime\Domain\Mcp\Services\SchemaValidator;
use Leantime\Domain\Mcp\Services\PlatformToolRegistry;
use Leantime\Domain\Mcp\Services\ToolCatalog;
use Leantime\Domain\Mcp\Services\ToolDispatcher;
use Leantime\Domain\Projects\Services\Projects;
use Leantime\Domain\Tickets\Services\Tickets;
use PHPUnit\Framework\TestCase;

final class ToolCatalogTest extends TestCase
{
    public function test_catalog_excludes_retired_idea_room_and_irreversible_tools(): void
    {
        $catalog = $this->catalog();
        $definitions = $catalog->definitions();
        $names = array_column($definitions, 'name');
        self::assertContains('addTask', $names);
        self::assertContains('bulkAddTasks', $names);
        self::assertContains('getProject', $names);
        self::assertContains('saveWhiteboardScene', $names);
        self::assertContains('patchWhiteboardScene', $names);
        self::assertContains('listSwotBlueprints', $names);
        self::assertContains('getSwotBlueprint', $names);
        self::assertContains('createSwotBlueprint', $names);
        self::assertContains('addSwotItems', $names);
        self::assertContains('addComment', $names);
        self::assertNotContains('createIdeaRoom', $names);
        self::assertNotContains('proposeIdeaRoomPlan', $names);
        self::assertNotContains('deleteEvent', $names);
        self::assertNotContains('addProject', $names);
        foreach ($definitions as $definition) {
            self::assertSame(['name', 'description', 'input_schema', 'project_scope', 'permission', 'effect', 'recovery'], array_keys($definition));
            self::assertSame('object', $definition['input_schema']['type']);
            self::assertFalse($definition['input_schema']['additionalProperties']);
            self::assertContains($definition['effect'], ['read', 'write', 'communication']);
        }
        self::assertSame('communication', $catalog->definition('addComment')['effect']);
        self::assertSame('write', $catalog->definition('bulkAddTasks')['effect']);
        self::assertStringNotContainsString('confirmation', $catalog->definition('bulkAddTasks')['description']);
        self::assertSame('none', $catalog->definition('createWhiteboard')['recovery']);
        self::assertSame('revision', $catalog->definition('saveWhiteboardScene')['recovery']);
        self::assertSame(0, $catalog->definition('restoreWhiteboardRevision')['input_schema']['properties']['sourceRevision']['minimum']);
    }

    public function test_mcp_tool_advertises_exact_same_schema_as_in_app_catalog(): void
    {
        $definition = $this->catalog()->definition('saveWhiteboardScene');
        $dispatcher = (new \ReflectionClass(ToolDispatcher::class))->newInstanceWithoutConstructor();
        $tool = new CatalogMcpTool($definition, $dispatcher);
        self::assertSame($definition['input_schema'], $tool->toArray()['inputSchema']);
        self::assertSame($definition['name'], $tool->toArray()['name']);
    }

    public function test_web_search_is_advertised_only_when_server_key_is_configured(): void
    {
        $previous = $_ENV['JULIANNA_WEB_SEARCH_API_KEY'] ?? null;
        try {
            $_ENV['JULIANNA_WEB_SEARCH_API_KEY'] = '';
            self::assertNotContains('searchWeb', array_column($this->catalog()->definitions(), 'name'));
            $_ENV['JULIANNA_WEB_SEARCH_API_KEY'] = 'test-search-key';
            $definition = $this->catalog()->definition('searchWeb');
            self::assertSame('read', $definition['effect']);
            self::assertSame('accessible', $definition['project_scope']);
            self::assertFalse($definition['input_schema']['additionalProperties']);
            self::assertSame('read', $this->catalog()->definition('researchWeb')['effect']);
        } finally {
            if ($previous === null) {
                unset($_ENV['JULIANNA_WEB_SEARCH_API_KEY']);
            } else {
                $_ENV['JULIANNA_WEB_SEARCH_API_KEY'] = $previous;
            }
        }
    }

    public function test_whiteboard_schema_rejects_unknown_fields_and_unbounded_lists(): void
    {
        $schema = $this->catalog()->definition('saveWhiteboardScene')['input_schema'];
        $validator = new SchemaValidator;
        $valid = [
            'boardId' => 1,
            'expectedRevision' => 0,
            'scene' => ['elements' => [], 'appState' => [], 'files' => []],
        ];
        $validator->validate($valid, $schema);

        foreach ([
            $valid + ['actorId' => 100],
            [...$valid, 'expectedRevision' => -1],
            [...$valid, 'scene' => ['elements' => array_fill(0, 5001, []), 'appState' => [], 'files' => []]],
        ] as $invalid) {
            try {
                $validator->validate($invalid, $schema);
                self::fail('Unexpected Whiteboard input accepted.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_patch_schema_bounds_changes_and_validates_element_ids(): void
    {
        $schema = $this->catalog()->definition('patchWhiteboardScene')['input_schema'];
        $validator = new SchemaValidator;
        $validator->validate([
            'boardId' => 3,
            'expectedRevision' => 0,
            'upsertElements' => [['id' => 'node-1', 'text' => 'Edited']],
        ], $schema);
        foreach ([
            ['boardId' => 3, 'expectedRevision' => 0, 'removeElementIds' => ['invalid id']],
            ['boardId' => 3, 'expectedRevision' => 0, 'upsertElements' => array_fill(0, 51, ['id' => 'node'])],
        ] as $invalid) {
            try {
                $validator->validate($invalid, $schema);
                self::fail('Unexpected Whiteboard patch accepted.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_swot_tool_schema_enforces_native_quadrants_and_bounded_entries(): void
    {
        $catalog = $this->catalog();
        $validator = new SchemaValidator;
        $schema = $catalog->definition('addSwotItems')['input_schema'];
        self::assertSame('swotBlueprint', $catalog->definition('addSwotItems')['project_scope']);
        self::assertSame('blueprints.create', $catalog->definition('addSwotItems')['permission']);
        $valid = ['boardId' => 1, 'items' => [['quadrant' => 'strengths', 'description' => 'Fast onboarding']]];
        $validator->validate($valid, $schema);
        foreach ([
            ['boardId' => 1, 'items' => [['quadrant' => 'other', 'description' => 'No']]],
            ['boardId' => 1, 'items' => array_fill(0, 21, $valid['items'][0])],
            ['boardId' => 1, 'items' => [['quadrant' => 'strengths', 'description' => '']]],
        ] as $invalid) {
            $this->expectInvalidSwotInput($validator, $invalid, $schema);
        }
    }

    private function expectInvalidSwotInput(SchemaValidator $validator, array $input, array $schema): void
    {
        try {
            $validator->validate($input, $schema);
            self::fail('Unexpected SWOT input accepted.');
        } catch (InvalidArgumentException) {
            self::assertTrue(true);
        }
    }

    private function catalog(): ToolCatalog
    {
        return new ToolCatalog(new PlatformToolRegistry(
            $this->createMock(PermissionService::class),
            $this->createMock(Projects::class),
            $this->createMock(Tickets::class),
            $this->createMock(Goalcanvas::class),
            $this->createMock(Calendar::class),
        ));
    }
}
