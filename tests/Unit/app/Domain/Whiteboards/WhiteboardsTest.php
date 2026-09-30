<?php

declare(strict_types=1);

namespace Unit\app\Domain\Whiteboards;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Leantime\Core\Auth\Permissions\PermissionService;
use Leantime\Core\Configuration\AppSettings;
use Leantime\Core\Exceptions\AuthorizationException;
use Leantime\Domain\Install\Services\SchemaBuilder;
use Leantime\Domain\Whiteboards\Services\WhiteboardAccess;
use Leantime\Domain\Whiteboards\Services\Whiteboards;
use Leantime\Domain\Whiteboards\Support\WhiteboardConflictException;
use Leantime\Domain\Whiteboards\Support\WhiteboardScene;
use PDO;
use Unit\TestCase;

final class WhiteboardsTest extends TestCase
{
    private SQLiteConnection $db;

    private Whiteboards $boards;

    protected function setUp(): void
    {
        parent::setUp();
        session()->put('userdata.id', 7);
        $this->db = new SQLiteConnection(new PDO('sqlite::memory:'));
        $schema = $this->db->getSchemaBuilder();
        $schema->create('zp_projects', static function (Blueprint $table): void {
            $table->id(); $table->string('state'); $table->string('psettings'); $table->integer('clientId');
        });
        $schema->create('zp_user', static function (Blueprint $table): void {
            $table->id(); $table->string('status'); $table->string('role'); $table->integer('clientId');
        });
        $schema->create('julianna_auth_accounts', static function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('user_id'); $table->string('state');
        });
        $schema->create('zp_relationuserproject', static function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('userId'); $table->unsignedBigInteger('projectId'); $table->string('projectRole');
        });
        Schema::swap($schema);
        (new SchemaBuilder(new AppSettings))->createWhiteboardTables();
        $this->db->table('zp_projects')->insert([
            ['id' => 11, 'state' => 'a', 'psettings' => 'limited', 'clientId' => 1],
            ['id' => 12, 'state' => 'a', 'psettings' => 'limited', 'clientId' => 2],
        ]);
        $this->db->table('zp_user')->insert(['id' => 7, 'status' => 'a', 'role' => '20', 'clientId' => 1]);
        $this->db->table('julianna_auth_accounts')->insert(['user_id' => 7, 'state' => 'active']);
        $this->db->table('zp_relationuserproject')->insert(['userId' => 7, 'projectId' => 11, 'projectRole' => '20']);
        $permissions = $this->createMock(PermissionService::class);
        $permissions->method('roleHasPermission')->willReturnCallback(
            static fn (string $role, string $permission): bool => $role === 'editor' || ($role === 'readonly' && $permission === 'whiteboards.view')
        );
        $this->boards = new Whiteboards($this->db, new WhiteboardAccess($this->db, $permissions));
    }

    public function test_empty_board_is_project_scoped_and_revisioned(): void
    {
        $board = $this->boards->createBoard(11, 7, '  Product sketch  ');
        self::assertSame('Product sketch', $board['title']);
        self::assertSame(0, $board['revision']);
        self::assertSame(WhiteboardScene::empty(), $board['scene']);
        self::assertCount(1, $this->boards->listBoards(11, 7));
        self::assertSame([0], array_column($this->boards->revisions($board['id'], 7), 'revision'));

        $this->expectException(AuthorizationException::class);
        $this->boards->listBoards(12, 7);
    }

    public function test_scene_save_conflict_restore_and_asset_persistence(): void
    {
        $board = $this->boards->createBoard(11, 7, 'Workshop');
        $image = base64_encode('png bytes');
        $scene = [
            'elements' => [$this->rectangle('shape_1')],
            'appState' => ['viewBackgroundColor' => '#ffffff', 'selectedElementIds' => ['shape_1' => true]],
            'files' => ['img_1' => ['id' => 'img_1', 'mimeType' => 'image/png', 'dataURL' => 'data:image/png;base64,'.$image]],
        ];
        $saved = $this->boards->saveScene($board['id'], 7, 0, $scene);
        self::assertSame(1, $saved['revision']);
        self::assertSame('data:image/png;base64,'.$image, $saved['scene']['files']['img_1']['dataURL']);
        self::assertArrayNotHasKey('selectedElementIds', $saved['scene']['appState']);
        self::assertSame(1, $this->db->table('julianna_whiteboard_assets')->count());
        self::assertSame(1, $this->boards->saveScene($board['id'], 7, 1, $scene)['revision']);
        self::assertSame(2, $this->db->table('julianna_whiteboard_revisions')->count());

        try {
            $this->boards->saveScene($board['id'], 7, 0, WhiteboardScene::empty());
            self::fail('Stale saves must fail.');
        } catch (WhiteboardConflictException) {
            self::assertSame(1, $this->boards->board($board['id'], 7)['revision']);
        }

        $restored = $this->boards->restoreRevision($board['id'], 7, 1, 0);
        self::assertSame(2, $restored['revision']);
        self::assertSame([], $restored['scene']['elements']);
        self::assertSame([2, 1, 0], array_column($this->boards->revisions($board['id'], 7), 'revision'));
        self::assertSame('shape_1', $this->boards->revision($board['id'], 7, 1)['scene']['elements'][0]['id']);
        $this->expectException(WhiteboardConflictException::class);
        $this->boards->restoreRevision($board['id'], 7, 1, 1);
    }

    public function test_revoke_access_and_disable_account_block_future_actions(): void
    {
        $board = $this->boards->createBoard(11, 7, 'Sprint');
        $this->db->table('zp_relationuserproject')->where('projectId', 11)->delete();
        try {
            $this->boards->board($board['id'], 7);
            self::fail('Revoked project membership must block reads.');
        } catch (AuthorizationException) {
            // Expected.
        }
        $this->db->table('zp_relationuserproject')->insert(['userId' => 7, 'projectId' => 11, 'projectRole' => '20']);
        $this->db->table('julianna_auth_accounts')->where('user_id', 7)->update(['state' => 'disabled']);
        $this->expectException(AuthorizationException::class);
        $this->boards->saveScene($board['id'], 7, 0, WhiteboardScene::empty());
    }

    public function test_readonly_can_view_but_not_write(): void
    {
        $board = $this->boards->createBoard(11, 7, 'Status');
        $this->db->table('zp_user')->where('id', 7)->update(['role' => '5']);
        $this->db->table('zp_relationuserproject')->where('userId', 7)->update(['projectRole' => '5']);
        self::assertSame($board['id'], $this->boards->board($board['id'], 7)['id']);
        $this->expectException(AuthorizationException::class);
        $this->boards->saveScene($board['id'], 7, 0, WhiteboardScene::empty());
    }

    public function test_rejects_remote_or_oversized_assets(): void
    {
        $board = $this->boards->createBoard(11, 7, 'Images');
        $scene = WhiteboardScene::empty();
        $scene['files'] = ['img_1' => ['id' => 'img_1', 'mimeType' => 'image/png', 'dataURL' => 'https://example.com/image.png']];
        $this->expectException(InvalidArgumentException::class);
        $this->boards->saveScene($board['id'], 7, 0, $scene);
    }

    public function test_rejects_sparse_or_cloud_elements_before_they_can_break_the_editor(): void
    {
        $scene = WhiteboardScene::empty();
        $scene['elements'] = [['id' => 'shape_1', 'type' => 'rectangle', 'x' => 12]];
        try {
            WhiteboardScene::normalize($scene);
            self::fail('Sparse model-generated shapes must be rejected.');
        } catch (InvalidArgumentException) {
            // Expected.
        }

        $scene['elements'] = [array_replace($this->rectangle('shape_1'), ['type' => 'iframe'])];
        $this->expectException(InvalidArgumentException::class);
        WhiteboardScene::normalize($scene);
    }

    /** @return array<string,mixed> */
    private function rectangle(string $id): array
    {
        return [
            'id' => $id, 'type' => 'rectangle', 'x' => 12, 'y' => 10,
            'width' => 200, 'height' => 90, 'angle' => 0,
            'strokeColor' => '#1e1e1e', 'backgroundColor' => 'transparent',
            'fillStyle' => 'solid', 'strokeWidth' => 2, 'strokeStyle' => 'solid',
            'roundness' => ['type' => 3], 'roughness' => 1, 'opacity' => 100,
            'seed' => 1024, 'version' => 1, 'versionNonce' => 5,
            'index' => null, 'isDeleted' => false, 'groupIds' => [],
            'frameId' => null, 'boundElements' => null, 'updated' => 1000,
            'link' => null, 'locked' => false,
        ];
    }
}
