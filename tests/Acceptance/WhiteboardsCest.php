<?php

declare(strict_types=1);

namespace Acceptance;

use Codeception\Attribute\Depends;
use Codeception\Attribute\Group;
use PHPUnit\Framework\Assert;
use Tests\Support\AcceptanceTester;
use Tests\Support\Page\Acceptance\Login;

/** Browser → Whiteboard API → durable revision → browser acceptance path. */
final class WhiteboardsCest
{
    public function _before(AcceptanceTester $I, Login $loginPage): void
    {
        $loginPage->login('owner@julianna.test', 'JuliannaTest123!');
    }

    #[Group('whiteboards')]
    #[Depends('Acceptance\InstallCest:createDBSuccessfully')]
    public function projectBoardPersistsScenesRejectsStaleEditsAndRestoresHistory(AcceptanceTester $I): void
    {
        $I->amOnPage('/whiteboards/projects/1');
        $I->waitForElementVisible('[data-whiteboards-index][data-project-id="1"]', 30);
        $I->fillField('#whiteboard-title', 'Acceptance workshop');
        $I->click('#whiteboard-create-form button[type="submit"]');
        $I->waitForJS('return /^\/whiteboards\/\d+$/.test(window.location.pathname);', 30);
        Assert::assertSame(1, preg_match('#/whiteboards/([0-9]+)$#',
            (string) parse_url($I->grabFromCurrentUrl(), PHP_URL_PATH), $matches));
        $boardId = (int) $matches[1];
        $I->waitForJS("return !!document.querySelector('#julianna-whiteboard-editor .excalidraw');", 60);
        $I->seeInDatabase('julianna_whiteboards', [
            'id' => $boardId, 'project_id' => 1, 'title' => 'Acceptance workshop', 'revision' => 0,
        ]);

        $scene = ['elements' => [], 'appState' => ['viewBackgroundColor' => '#ffcc00'], 'files' => []];
        $saved = $this->browserJson($I, 'PUT', '/whiteboards/'.$boardId.'/scene', [
            'expectedRevision' => 0, 'scene' => $scene,
        ]);
        Assert::assertSame(200, $saved['status'], json_encode($saved));
        Assert::assertSame(1, $saved['body']['revision'] ?? null);
        $I->seeInDatabase('julianna_whiteboards', ['id' => $boardId, 'revision' => 1]);

        $stale = $this->browserJson($I, 'PUT', '/whiteboards/'.$boardId.'/scene', [
            'expectedRevision' => 0, 'scene' => ['elements' => [], 'appState' => ['viewBackgroundColor' => '#000000'], 'files' => []],
        ]);
        Assert::assertSame(409, $stale['status'], json_encode($stale));
        $I->seeInDatabase('julianna_whiteboards', ['id' => $boardId, 'revision' => 1]);

        $I->reloadPage();
        $I->waitForText('Revision 1', 30, '#whiteboard-revision');
        $I->selectOption('#whiteboard-history', '0');
        $I->click('#whiteboard-restore');
        $I->acceptPopup();
        $I->waitForText('Revision 2', 30, '#whiteboard-revision');
        $I->seeInDatabase('julianna_whiteboards', ['id' => $boardId, 'revision' => 2]);
        $restored = $this->browserJson($I, 'GET', '/whiteboards/'.$boardId.'/scene');
        Assert::assertSame(200, $restored['status'], json_encode($restored));
        Assert::assertSame('#ffffff', $restored['body']['scene']['appState']['viewBackgroundColor'] ?? null);
        $revisions = $this->browserJson($I, 'GET', '/whiteboards/'.$boardId.'/revisions');
        Assert::assertSame([2, 1, 0], array_column($revisions['body']['revisions'] ?? [], 'revision'));
    }

    /** @return array{status:int,body:array<string,mixed>} */
    private function browserJson(AcceptanceTester $I, string $method, string $path, ?array $body = null): array
    {
        $result = $I->executeAsyncJS(<<<'JS'
            const done = arguments[arguments.length - 1];
            const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
            fetch(arguments[0], {
                method: arguments[1], credentials: 'same-origin',
                headers: {'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf},
                body: arguments[2] === null ? undefined : JSON.stringify(arguments[2]),
            }).then(async response => done({status: response.status, body: await response.json()}))
              .catch(error => done({status: 0, body: {error: String(error)}}));
            JS, [$path, $method, $body]);
        Assert::assertIsArray($result);

        return $result;
    }
}
