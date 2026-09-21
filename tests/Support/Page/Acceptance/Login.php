<?php

declare(strict_types=1);

namespace Tests\Support\Page\Acceptance;

use Codeception\Util\Fixtures;
use RobThree\Auth\TwoFactorAuth;

class Login
{
    /**
     * @var \Tests\Support\AcceptanceTester;
     */
    protected $I;

    protected Install $installPage;

    public function __construct(\Tests\Support\AcceptanceTester $I, Install $installPage)
    {
        $this->I = $I;
        $this->installPage = $installPage;
    }

    public function login($username, $password)
    {
        if ($this->loadSessionShapshot('julianna_session')) {
            $this->I->amOnPage('/dashboard/home');
            if (! str_contains($this->I->grabFromCurrentUrl(), '/auth/login')) {
                return;
            }

            // The server-side registry may have revoked a cached cookie after
            // logout, password reset, or administrative disablement.
            Fixtures::cleanup('julianna_session');
        }

        if (! Fixtures::exists('installed')) {
            $this->installPage->install(
                'owner@julianna.test',
                'JuliannaTest123!',
                'John',
                'Smith',
                'Smith & Co'
            );
        }

        $this->I->amOnPage('/auth/login');
        $this->I->waitForElementVisible('#login', 30);
        $this->I->fillField(['name' => 'username'], $username);
        $this->I->fillField(['name' => 'password'], $password);
        $this->I->click('Login');

        $this->I->waitForElementVisible('#code', 90);
        $secret = (string) Fixtures::get('owner_totp_secret');
        $this->I->fillField('#code', (new TwoFactorAuth('Julianna', 6, 30, 'sha1'))->getCode($secret));
        $this->I->click('input[type="submit"]');
        $this->I->waitForElementVisible('.welcome-widget', 120);
        $this->I->see('Hi John');

        $this->saveSessionSnapshot('julianna_session');
    }

    protected function loadSessionShapshot(string $name): bool
    {
        if (! Fixtures::exists($name)) {
            return false;
        }

        $this->I->setCookie($name, Fixtures::get($name));

        return true;
    }

    protected function saveSessionSnapshot(string $name): void
    {
        Fixtures::add($name, $this->I->grabCookie($name));
    }
}
