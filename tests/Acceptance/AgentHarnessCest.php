<?php

declare(strict_types=1);

namespace Acceptance;

use Codeception\Attribute\Depends;
use Codeception\Attribute\Group;
use Codeception\Scenario;
use PHPUnit\Framework\Assert;
use Tests\Support\AcceptanceTester;
use Tests\Support\Page\Acceptance\Login;

/** Browser contract for the active Agent experience and retired Idea Room. */
final class AgentHarnessCest
{
    public function _before(AcceptanceTester $I, Login $loginPage): void
    {
        $loginPage->login('owner@julianna.test', 'JuliannaTest123!');
    }

    #[Group('agent-harness')]
    #[Depends('Acceptance\InstallCest:createDBSuccessfully')]
    public function commandCenterAndSiteWideDrawerKeepProjectContext(AcceptanceTester $I): void
    {
        $I->amOnPage('/agent');
        $I->waitForElementVisible('[data-agent-command-center]', 30);
        $I->see('Give Julianna a task');
        $I->seeElement('#agent-conversation-select');
        $I->seeElement('#agent-questions');
        $I->seeElement('#agent-drafts');
        $I->seeElement('#agent-activities');
        $I->seeElement('#agent-runs');
        $I->dontSeeElement('a[href$="/idea-room"]');

        $I->amOnPage('/agent/projects/1');
        $I->waitForElementVisible('[data-agent-command-center][data-project-id="1"]', 30);
        $I->seeElement('a.agent-whiteboard-link[href$="/whiteboards/projects/1"]');
        $I->click('#julianna-agent-launcher');
        $I->waitForElementVisible('#julianna-agent-drawer', 30);
        $I->waitForJS("return document.querySelector('#julianna-agent-scope').value === '1';", 30);
        $I->see('/agent/projects/1', '#julianna-agent-page');
        $I->click('#julianna-agent-close');
        $I->dontSeeElement('#julianna-agent-drawer');
    }

    #[Group('agent-harness')]
    #[Depends('Acceptance\InstallCest:createDBSuccessfully')]
    public function missingProviderShowsSetupWithoutPerformingWork(AcceptanceTester $I, Scenario $scenario): void
    {
        $I->amOnPage('/agent/projects/1');
        $I->waitForElementVisible('[data-agent-command-center]', 30);
        if ($I->grabAttributeFrom('[data-agent-command-center]', 'data-provider-configured') !== '0') {
            $scenario->skip('Run this provider-missing case with no deployment-level AI provider configured.');
        }

        $I->see('AI is not configured.');
        $before = $I->grabNumRecords('julianna_agent_activities', ['project_id' => 1]);
        $I->fillField('#agent-message', 'Please summarize this project.');
        $I->click('#agent-send');
        $I->waitForText('A server-side AI provider is not configured.', 30, '#agent-feedback');
        Assert::assertSame($before, $I->grabNumRecords('julianna_agent_activities', ['project_id' => 1]));
    }

    #[Group('agent-harness')]
    #[Depends('Acceptance\InstallCest:createDBSuccessfully')]
    public function archivedRoomKeepsTranscriptCanvasHistoryAndRejectsOldWrites(AcceptanceTester $I): void
    {
        $ownerId = (int) $I->grabFromDatabase('zp_user', 'id', ['username' => 'owner@julianna.test']);
        Assert::assertGreaterThan(0, $ownerId);
        $now = gmdate('Y-m-d H:i:s');
        $plan = ['outcome' => 'Preserve the original decision', 'milestones' => [], 'tasks' => [], 'assumptions' => [], 'openQuestions' => []];
        $roomId = $I->haveInDatabase('julianna_idea_rooms', [
            'owner_user_id' => $ownerId,
            'project_id' => 1,
            'status' => 'active',
            'title' => 'Archived browser fixture',
            'plan_json' => json_encode($plan, JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $I->haveInDatabase('julianna_idea_messages', [
            'room_id' => $roomId,
            'role' => 'user',
            'content' => 'Original conversation retained',
            'created_at' => $now,
        ]);
        $nodeId = $I->haveInDatabase('julianna_idea_graph_nodes', [
            'room_id' => $roomId,
            'type' => 'note',
            'title' => 'Original canvas node',
            'content' => 'A preserved idea',
            'position_x' => 20,
            'position_y' => 30,
            'metadata_json' => '{}',
            'author_user_id' => $ownerId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $graph = ['mode' => 'explore', 'nodes' => [[
            'id' => $nodeId, 'type' => 'note', 'title' => 'Original canvas node',
            'content' => 'A preserved idea', 'metadata' => [],
        ]], 'links' => []];
        $historyId = $I->haveInDatabase('julianna_idea_history', [
            'room_id' => $roomId,
            'author_user_id' => $ownerId,
            'origin' => 'manual',
            'summary' => 'Original revision',
            'graph_version' => 0,
            'plan_version' => 0,
            'graph_json' => json_encode($graph, JSON_THROW_ON_ERROR),
            'plan_json' => json_encode($plan, JSON_THROW_ON_ERROR),
            'created_at' => $now,
        ]);
        $actionId = $I->haveInDatabase('julianna_idea_actions', [
            'room_id' => $roomId,
            'tool_call_id' => 'historical-request-'.$roomId,
            'tool_name' => 'addTask',
            'arguments_json' => '{}',
            'status' => 'pending',
            'destructive' => 0,
            'idempotency_key' => hash('sha256', 'historical-request-'.$roomId),
            'expires_at' => gmdate('Y-m-d H:i:s', time() + 3600),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $I->amOnPage('/idea-room/'.$roomId);
        $I->waitForElementVisible('#archive-transcript-heading', 30);
        $I->seeInCurrentUrl('/agent/archive/'.$roomId);
        $I->see('Read only');
        $I->see('Original conversation retained');
        $I->see('Original canvas node');
        $I->see('Preserve the original decision');
        $I->see('Original revision');
        $I->see('Unexecuted historical requests');
        $I->dontSeeElement('form[action*="/idea-room"]');

        $I->amOnPage('/agent/archive/'.$roomId.'/revisions/'.$historyId);
        $I->waitForElementVisible('#archive-canvas-heading', 30);
        $I->see('Original canvas node');
        $I->see('Read only');

        $result = $this->browserJson($I, 'POST', '/idea-room/'.$roomId.'/send', ['message' => 'Do not execute']);
        Assert::assertSame(410, $result['status']);
        $I->seeInDatabase('julianna_idea_actions', ['id' => $actionId, 'status' => 'pending']);
    }

    /** @return array{status:int,body:array<string,mixed>} */
    private function browserJson(AcceptanceTester $I, string $method, string $path, array $body): array
    {
        $result = $I->executeAsyncJS(<<<'JS'
            const done = arguments[arguments.length - 1];
            const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
            fetch(arguments[0], {
                method: arguments[1], credentials: 'same-origin',
                headers: {'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf},
                body: JSON.stringify(arguments[2]),
            }).then(async response => done({status: response.status, body: await response.json()}))
              .catch(error => done({status: 0, body: {error: String(error)}}));
            JS, [$path, $method, $body]);
        Assert::assertIsArray($result);

        return $result;
    }
}
