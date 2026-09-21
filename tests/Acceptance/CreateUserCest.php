<?php

namespace Acceptance;

use Codeception\Attribute\Depends;
use Codeception\Attribute\Group;
use Codeception\Util\Fixtures;
use RobThree\Auth\TwoFactorAuth;
use Tests\Support\AcceptanceTester;
use Tests\Support\Page\Acceptance\Login;

class CreateUserCest
{
    public function _before(AcceptanceTester $I, Login $loginPage): void
    {
        $loginPage->login('owner@julianna.test', 'JuliannaTest123!');
    }

    #[Group('user')]
    #[Depends('Acceptance\LoginCest:loginSuccessfully')]
    public function createAUser(AcceptanceTester $I): void
    {
        $I->wantTo('Complete signup, verification, approval, and mandatory MFA enrollment');

        // Public registration returns the same response regardless of whether
        // the email already exists. Keep the owner session active so it can
        // approve the request after the public verification route.
        $I->amOnPage('/auth/register');
        $I->waitForElementVisible('#name', 30);
        $I->fillField('#name', 'John Doe');
        $I->fillField('#email', 'john@julianna.test');
        $I->fillField('#password', 'MemberPassword123!');
        $I->fillField('#password_confirmation', 'MemberPassword123!');
        $I->click('input[type="submit"]');
        $I->waitForText('If the request can be processed', 30);

        $accountId = (int) $I->grabFromDatabase('julianna_auth_accounts', 'id', [
            'email_normalized' => 'john@julianna.test',
        ]);
        $verificationToken = 'acceptance-verification-token-john-doe';
        $I->haveInDatabase('julianna_auth_tokens', [
            'account_id' => $accountId,
            'purpose' => 'email_verification',
            'token_hash' => hash('sha256', $verificationToken),
            'expires_at' => gmdate('Y-m-d H:i:s', time() + 3600),
            'consumed_at' => null,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);

        $I->amOnPage('/auth/verifyEmail/'.$verificationToken);
        $I->waitForText('If this verification link was valid', 30);
        $I->seeInDatabase('julianna_auth_accounts', [
            'id' => $accountId,
            'state' => 'pending_approval',
        ]);

        $I->amOnPage('/users/approvals');
        $I->waitForText('John Doe', 30);
        $I->selectOption('#role-'.$accountId, '5');
        $I->click('Approve');
        $I->waitForText('The signup decision was saved.', 30);

        $I->seeInDatabase('zp_user', [
            'username' => 'john@julianna.test',
            'role' => '5',
            'status' => 'a',
        ]);
        $I->seeInDatabase('julianna_auth_accounts', [
            'id' => $accountId,
            'state' => 'active',
        ]);

        // The approved member still cannot enter the application until TOTP
        // enrollment and recovery-code acknowledgement are complete.
        $I->amOnPage('/auth/logout');
        Fixtures::cleanup('julianna_session');
        $I->amOnPage('/auth/login');
        $I->fillField('#username', 'john@julianna.test');
        $I->fillField('#password', 'MemberPassword123!');
        $I->click(['name' => 'login']);
        $I->waitForElementVisible('#code', 30);
        $secret = trim($I->grabTextFrom('code'));
        $I->fillField('#code', (new TwoFactorAuth('Julianna', 6, 30, 'sha1'))->getCode($secret));
        $I->click('input[type="submit"]');
        $I->waitForElementVisible('input[name="saved"]', 30);
        $I->checkOption('input[name="saved"]');
        $I->click('input[type="submit"]');
        $I->waitForElementVisible('#firstname', 30);
        $I->seeInField('#firstname', 'John');
        $I->seeInCurrentUrl('/users/editOwn');
        $I->amOnPage('/auth/logout');
    }

    #[Group('user')]
    #[Depends('Acceptance\LoginCest:loginSuccessfully')]
    public function editAUser(AcceptanceTester $I): void
    {
        $I->wantTo('Edit a user');

        // Set CSRF token before making the request
        $I->setCSRFToken();
        $I->amOnPage('/users/editUser/1/');
        $I->waitForElement('.pagetitle', 120);
        $I->see('Edit User');
        $I->fillField(['name' => 'jobTitle'], 'Testing');
        $I->clickWithRetry('#save');
        $I->waitForElement('.growl', 120);
        $I->seeInSource('User edited successfully');
    }
}
