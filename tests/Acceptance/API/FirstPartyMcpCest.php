<?php

declare(strict_types=1);

namespace Acceptance\API;

use Codeception\Attribute\Group;
use PHPUnit\Framework\Assert;
use Tests\Support\AcceptanceTester;
use Tests\Support\Page\Acceptance\Install;

/** First-party /mcp contract; no private plugin installation or skip path. */
final class FirstPartyMcpCest
{
    public function _before(AcceptanceTester $I, Install $installPage): void
    {
        $installPage->install('owner@julianna.test', 'JuliannaTest123!', 'John', 'Smith', 'Smith & Co');
    }

    #[Group('mcp')]
    public function mcpRequiresTokenEvenWhenBrowserIsLoggedIn(AcceptanceTester $I): void
    {
        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->sendPost('/mcp', json_encode($this->request('initialize', [
            'protocolVersion' => '2025-11-25',
            'capabilities' => new \stdClass,
            'clientInfo' => ['name' => 'julianna-test', 'version' => '1'],
        ]), JSON_THROW_ON_ERROR));
        $I->seeResponseCodeIs(401);
    }

    #[Group('mcp')]
    public function mcpAndInAppCatalogHaveSameSafeCapabilities(AcceptanceTester $I): void
    {
        $this->mintBearerToken($I);
        $initialized = $this->mcp($I, 'initialize', [
            'protocolVersion' => '2025-11-25',
            'capabilities' => new \stdClass,
            'clientInfo' => ['name' => 'julianna-test', 'version' => '1'],
        ]);
        Assert::assertSame('2025-11-25', $initialized['result']['protocolVersion'] ?? null);
        Assert::assertSame('Julianna', $initialized['result']['serverInfo']['name'] ?? null);

        $listed = $this->mcp($I, 'tools/list', new \stdClass);
        $names = array_column($listed['result']['tools'] ?? [], 'name');
        Assert::assertContains('addTask', $names);
        Assert::assertContains('getProject', $names);
        Assert::assertContains('saveWhiteboardScene', $names);
        Assert::assertNotContains('createIdeaRoom', $names);
        Assert::assertNotContains('proposeIdeaRoomPlan', $names);
        Assert::assertNotContains('deleteEvent', $names);
        Assert::assertEmpty($listed['result']['nextCursor'] ?? null, 'The small first-party catalog should fit in one page.');

        $read = $this->call($I, 'getProject', ['projectId' => 1]);
        Assert::assertFalse($read['result']['isError'] ?? true, 'Accessible project read failed: '.json_encode($read));
        $missing = $this->call($I, 'getProject', ['projectId' => 9999999]);
        Assert::assertTrue($missing['result']['isError'] ?? false);
    }

    #[Group('mcp')]
    public function mcpTaskWriteUsesSamePermissionCheckedDispatcher(AcceptanceTester $I): void
    {
        $this->mintBearerToken($I);
        $created = $this->call($I, 'addTask', ['projectId' => 1, 'headline' => 'First-party MCP task']);
        Assert::assertFalse($created['result']['isError'] ?? true, 'Task creation failed: '.json_encode($created));
        Assert::assertSame(1, preg_match('/ID:?\s*(\d+)/', $this->text($created), $matches));
        $taskId = (int) $matches[1];

        $read = $this->call($I, 'getTicket', ['id' => $taskId]);
        Assert::assertFalse($read['result']['isError'] ?? true);
        Assert::assertStringContainsString('First-party MCP task', $this->text($read));
    }

    #[Group('mcp')]
    public function mcpWhiteboardPatchIsVersionedAndRecoverable(AcceptanceTester $I): void
    {
        $this->mintBearerToken($I);
        $created = $this->call($I, 'createWhiteboard', ['projectId' => 1, 'title' => 'MCP workshop']);
        Assert::assertFalse($created['result']['isError'] ?? true, 'Whiteboard creation failed: '.json_encode($created));
        $createdSummary = json_decode($this->text($created), true, 512, JSON_THROW_ON_ERROR);
        $boardId = (int) ($createdSummary['boardId'] ?? 0);
        Assert::assertGreaterThan(0, $boardId);

        $changed = $this->call($I, 'patchWhiteboardScene', [
            'boardId' => $boardId, 'expectedRevision' => 0, 'backgroundColor' => '#FFCC00',
            'upsertElements' => [
                ['id' => 'mcp-shape-1', 'type' => 'rectangle', 'x' => 20, 'y' => 30],
                ['id' => 'mcp-note-1', 'type' => 'text', 'x' => 50, 'y' => 60, 'text' => 'First move'],
            ],
        ]);
        Assert::assertFalse($changed['result']['isError'] ?? true, 'Whiteboard patch failed: '.json_encode($changed));
        $changedSummary = json_decode($this->text($changed), true, 512, JSON_THROW_ON_ERROR);
        Assert::assertSame(1, $changedSummary['revision'] ?? null);

        $read = $this->call($I, 'getWhiteboard', ['boardId' => $boardId]);
        Assert::assertFalse($read['result']['isError'] ?? true);
        $preview = json_decode($this->text($read), true, 512, JSON_THROW_ON_ERROR);
        Assert::assertSame(2, $preview['elementCount'] ?? null);
        Assert::assertSame('First move', $preview['elementsPreview'][1]['text'] ?? null);

        $stale = $this->call($I, 'patchWhiteboardScene', [
            'boardId' => $boardId, 'expectedRevision' => 0, 'backgroundColor' => '#000000',
        ]);
        Assert::assertTrue($stale['result']['isError'] ?? false);

        $restored = $this->call($I, 'restoreWhiteboardRevision', [
            'boardId' => $boardId, 'expectedRevision' => 1, 'sourceRevision' => 0,
        ]);
        Assert::assertFalse($restored['result']['isError'] ?? true);
        $restoredSummary = json_decode($this->text($restored), true, 512, JSON_THROW_ON_ERROR);
        Assert::assertSame(2, $restoredSummary['revision'] ?? null);
    }

    private function mintBearerToken(AcceptanceTester $I): void
    {
        $userId = (int) $I->grabFromDatabase('zp_user', 'id', ['username' => 'owner@julianna.test']);
        Assert::assertGreaterThan(0, $userId);
        $token = bin2hex(random_bytes(20));
        $I->haveInDatabase('zp_access_tokens', [
            'tokenable_type' => 'Leantime\\Domain\\Auth\\Services\\Auth',
            'tokenable_id' => $userId,
            'name' => 'first-party-mcp-cest',
            'token' => hash('sha256', $token),
            'abilities' => json_encode(['*']),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        $I->haveHttpHeader('Authorization', 'Bearer '.$token);
    }

    private function mcp(AcceptanceTester $I, string $method, array|\stdClass $params): array
    {
        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->haveHttpHeader('Accept', 'application/json');
        $I->sendPost('/mcp', json_encode($this->request($method, $params), JSON_THROW_ON_ERROR));
        $I->seeResponseCodeIs(200);
        $I->seeResponseIsJson();

        return json_decode($I->grabResponse(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function call(AcceptanceTester $I, string $name, array $arguments): array
    {
        return $this->mcp($I, 'tools/call', ['name' => $name, 'arguments' => $arguments]);
    }

    private function request(string $method, array|\stdClass $params): array
    {
        return ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params];
    }

    private function text(array $response): string
    {
        return $response['result']['content'][0]['text'] ?? '';
    }
}
