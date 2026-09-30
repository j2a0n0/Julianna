<?php

namespace Acceptance;

use Codeception\Attribute\Depends;
use Codeception\Attribute\Group;
use Codeception\Util\Fixtures;
use Tests\Support\AcceptanceTester;
use Tests\Support\Page\Acceptance\Login;

class LoginCest
{
    public function _before(AcceptanceTester $I) {}

    #[Group('login')]
    #[Depends('Acceptance\InstallCest:createDBSuccessfully')]
    public function loginPageWorks(AcceptanceTester $I): void
    {
        $I->amOnPage('/auth/login');
        $I->see('Login');
    }

    #[Group('login')]
    #[Depends('Acceptance\InstallCest:createDBSuccessfully')]
    public function loginDeniedForWrongCredentials(AcceptanceTester $I): void
    {
        $I->amOnPage('/auth/login');
        $I->waitForElementVisible('#login', 10);
        $I->fillField(['name' => 'username'], 'owner@julianna.test');
        $I->fillField(['name' => 'password'], 'WrongPassword');
        $I->click('Login');
        $I->waitForElementVisible('.login-alert');

        $I->see('Username or password incorrect!');
    }

    #[Group('login')]
    #[Depends('Acceptance\InstallCest:createDBSuccessfully')]
    public function loginSuccessfully(AcceptanceTester $I, Login $loginPage): void
    {
        $loginPage->login('owner@julianna.test', 'JuliannaTest123!');
    }

    #[Group('login')]
    #[Depends('loginSuccessfully')]
    public function invalidTotpAndRecoveryCodeReuseAreRejected(AcceptanceTester $I): void
    {
        $I->amOnPage('/auth/logout');
        Fixtures::cleanup('julianna_session');

        $this->submitPrimaryCredentials($I);
        $I->fillField('#code', 'not-a-valid-code');
        $I->click('input[type="submit"]');
        $I->waitForElementVisible('.login-alert', 30);
        $I->see('Incorrect code given. Please try again');

        $I->amOnPage('/auth/recovery');
        $I->waitForElementVisible('#code', 30);
        $recoveryCode = (string) Fixtures::get('owner_recovery_code');
        $I->fillField('#code', $recoveryCode);
        $I->click('input[type="submit"]');
        $I->waitForElementVisible('[data-agent-command-center]', 120);

        $I->amOnPage('/auth/logout');
        $this->submitPrimaryCredentials($I);
        $I->amOnPage('/auth/recovery');
        $I->fillField('#code', $recoveryCode);
        $I->click('input[type="submit"]');
        $I->waitForElementVisible('.login-alert', 30);
        $I->see('That recovery code is invalid or has already been used.');
        $I->amOnPage('/auth/logout');
    }

    private function submitPrimaryCredentials(AcceptanceTester $I): void
    {
        $I->amOnPage('/auth/login');
        $I->waitForElementVisible('#login', 30);
        $I->fillField('#username', 'owner@julianna.test');
        $I->fillField('#password', 'JuliannaTest123!');
        $I->click(['name' => 'login']);
        $I->waitForElementVisible('#code', 30);
    }
}
