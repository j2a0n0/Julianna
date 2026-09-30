<?php

declare(strict_types=1);

namespace Acceptance;

use Codeception\Attribute\Depends;
use Codeception\Attribute\Group;
use Codeception\Util\Fixtures;
use Leantime\Domain\Setting\Repositories\Setting as SettingRepository;
use PHPUnit\Framework\Assert;
use Tests\Support\AcceptanceTester;
use Tests\Support\Page\Acceptance\Login;

class FrenchLocalizationCest
{
    private const RAW_TRANSLATION_KEY = '/\b(?:buttons|headlines|input\.placeholders|label|links|menu|notification|notifications|tabs|text)\.[a-z][a-z0-9_.-]+\b/i';

    private const FORBIDDEN_ENGLISH_UI = [
        'Username or password incorrect!',
        'Show Closed Projects',
        'All Users',
        'All Statuses',
        'Show Tasks',
        'Method not implemented',
        'Nothing to see here. Move on.',
        'Due this week',
        'Due later',
        'My Favorites',
        "You don't have any favorites",
        'All Assigned Projects',
        'Widget Manager',
        'Tasks completed (last 7 days)',
        'Total tasks left',
    ];

    public function _before(AcceptanceTester $I, Login $loginPage): void
    {
        $loginPage->login('owner@julianna.test', 'JuliannaTest123!');
    }

    public function _after(AcceptanceTester $I): void
    {
        $ownerId = (int) $I->grabFromDatabase('zp_user', 'id', ['username' => 'owner@julianna.test']);
        // Use the application repository so both the database and its shared
        // setting cache are restored for the tests that follow this one.
        $I->getApplication()->make(SettingRepository::class)->saveSetting(
            'usersettings.'.$ownerId.'.language',
            'en-US',
        );

        // Do not let either the persisted locale or the cached browser session
        // make later, English-language acceptance tests order-dependent.
        Fixtures::cleanup('julianna_session');
        $I->resetCookie('julianna_session');
        $I->resetCookie('language');
    }

    #[Group('fr-ch-localization')]
    #[Depends('Acceptance\InstallCest:createDBSuccessfully')]
    public function coreExperienceStaysInSwissFrench(AcceptanceTester $I): void
    {
        $I->amOnPage('/users/editOwn#settings');
        $I->waitForElementVisible('#language_chosen', 30);
        $I->executeJS(<<<'JS'
            const language = document.querySelector('#language');
            language.value = 'fr-CH';
            language.dispatchEvent(new Event('change', { bubbles: true }));
            JS);
        $I->clickWithRetry('#saveSettings');
        $I->waitForText('Les paramètres du profil sont enregistrés avec succès', 30);

        $ownerId = (int) $I->grabFromDatabase('zp_user', 'id', ['username' => 'owner@julianna.test']);
        $I->seeInDatabase('zp_settings', [
            'key' => 'usersettings.'.$ownerId.'.language',
            'value' => 'fr-CH',
        ]);

        $this->assertFrenchPage($I, '/dashboard/home', 'Accueil');
        $this->assertFrenchPage($I, '/projects/showAll', 'Tous les projets');
        $this->assertFrenchPage($I, '/tickets/showKanban', 'Tâches');
        $this->assertFrenchPage($I, '/goalcanvas/dashboard', 'Objectifs');
        $this->assertFrenchPage($I, '/calendar/showMyCalendar', 'Calendrier');
        $this->assertFrenchPage($I, '/timesheets/showMy', 'Ma feuille de temps');
        $this->assertFrenchPage($I, '/users/editOwn#settings', 'Langue');
        $this->assertFrenchPage($I, '/setting/editCompanySettings', 'Paramètres');
        $this->assertFrenchPage($I, '/agent', 'Qu’est-ce qu’on fait avancer aujourd’hui ?');
        $I->see('Confier une tâche à Julianna');
        $I->dontSee('Give Julianna a task');
        $this->assertFrenchPage($I, '/agent/projects/1', 'Périmètre du projet');
        $this->assertFrenchPage($I, '/agent/archive', 'Archives d’Idea Room');
        $this->assertFrenchPage($I, '/whiteboards/projects/1', 'Tableaux blancs');
        $I->fillField('#whiteboard-title', 'Atelier de test');
        $I->click('#whiteboard-create-form button[type="submit"]');
        $I->waitForJS('return /^\/whiteboards\/\d+$/.test(window.location.pathname);', 30);
        $I->waitForJS("return !!document.querySelector('#julianna-whiteboard-editor .excalidraw');", 60);
        $I->seeElement('html[lang="fr"]');
        $I->seeElement('#julianna-whiteboard-editor[data-locale="fr-FR"]');
        $I->see('Enregistrer');
        $I->dontSee('Could not save. Please retry.');
        $this->assertFrenchPage($I, '/route-inexistante-pour-test', 'Oups, quelque chose ne va pas.');

        Assert::assertSame('fr-CH', $I->grabCookie('language'), 'The selected locale cookie must persist before logout.');
        $I->amOnPage('/auth/logout');
        $I->amOnPage('/auth/login');
        $I->waitForElementVisible('#login', 30);
        Assert::assertSame('fr-CH', $I->grabCookie('language'), 'Logout must preserve the selected locale cookie.');
        $this->assertCurrentPageIsFrench($I, 'Bienvenue de retour');
        $I->fillField(['name' => 'username'], 'owner@julianna.test');
        $I->fillField(['name' => 'password'], 'mot-de-passe-incorrect');
        $I->click(['name' => 'login']);
        $I->waitForElementVisible('.login-alert', 30);
        $I->see('Nom d’utilisateur ou mot de passe incorrect');
        $this->assertCurrentPageIsFrench($I, 'Bienvenue de retour');
    }

    private function assertFrenchPage(AcceptanceTester $I, string $path, string $expectedText): void
    {
        $I->amOnPage($path);
        $I->waitForElementVisible('body', 30);
        $this->assertCurrentPageIsFrench($I, $expectedText);
    }

    private function assertCurrentPageIsFrench(AcceptanceTester $I, string $expectedText): void
    {
        $I->seeElement('html[lang="fr"]');
        $I->see($expectedText);

        $visibleText = $I->grabTextFrom('body');
        Assert::assertSame(0, preg_match(self::RAW_TRANSLATION_KEY, $visibleText), 'A raw translation key is visible.');
        foreach (self::FORBIDDEN_ENGLISH_UI as $englishCopy) {
            $I->dontSee($englishCopy);
        }
    }
}
