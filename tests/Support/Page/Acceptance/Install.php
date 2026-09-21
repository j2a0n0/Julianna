<?php

declare(strict_types=1);

namespace Tests\Support\Page\Acceptance;

use Codeception\Util\Fixtures;
use RobThree\Auth\TwoFactorAuth;
use Tests\Support\AcceptanceTester;

class Install
{
    protected AcceptanceTester $I;

    protected $app;

    public function __construct(AcceptanceTester $I)
    {
        $this->I = $I;
        $this->app = $I->getApplication();
    }

    public function install($email, $password, $firstname, $lastname, $company): void
    {
        if (Fixtures::exists('installed')) {
            $this->suppressModals();

            return;
        }

        $this->I->amOnPage('/install');
        $this->I->fillField(['name' => 'email'], $email);
        $this->I->fillField(['name' => 'firstname'], $firstname);
        $this->I->fillField(['name' => 'lastname'], $lastname);
        $this->I->fillField(['name' => 'company'], $company);
        $this->I->click('Install');

        // The installer creates the first owner in Julianna's identity store
        // and redirects to its single-use password setup token.
        $this->I->waitForElementVisible(['name' => 'password'], 90);
        $this->I->fillField(['name' => 'password'], $password);
        $this->I->fillField(['name' => 'password2'], $password);
        $this->I->click(['name' => 'resetPassword']);

        $this->I->waitForElementVisible('#login', 90);
        $this->I->fillField(['name' => 'username'], $email);
        $this->I->fillField(['name' => 'password'], $password);
        $this->I->click(['name' => 'login']);

        // First access is not a full application session until TOTP is
        // enrolled and the recovery codes have been acknowledged.
        $this->I->waitForElementVisible('#code', 90);
        $secret = trim($this->I->grabTextFrom('code'));
        Fixtures::add('owner_totp_secret', $secret);

        $totp = new TwoFactorAuth('Julianna', 6, 30, 'sha1');
        $this->I->fillField('#code', $totp->getCode($secret));
        $this->I->click('input[type="submit"]');

        $this->I->waitForElementVisible('input[name="saved"]', 90);
        Fixtures::add('owner_recovery_code', trim($this->I->grabTextFrom('#recovery-codes li')));
        $this->I->checkOption('input[name="saved"]');
        $this->I->click('input[type="submit"]');
        $this->I->waitForElementVisible('.welcome-widget', 120);

        Fixtures::add('installed', true);
        $this->suppressModals();
    }

    /**
     * Suppress all helper modals for testing
     */
    private function suppressModals(): void
    {
        $userService = $this->app->make(\Leantime\Domain\Users\Services\Users::class);
        session(['userdata.id' => 1]);

        // Suppress all known modals
        $userService->updateUserSettings('modals', 'projectDashboard', true);
        $userService->updateUserSettings('modals', 'home', true);
        $userService->updateUserSettings('modals', 'kanban', true);
        $userService->updateUserSettings('modals', 'roadmap', true);
        $userService->updateUserSettings('modals', 'goals', true);
    }
}
