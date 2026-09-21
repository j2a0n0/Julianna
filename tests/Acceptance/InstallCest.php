<?php

namespace Acceptance;

use Codeception\Attribute\Depends;
use Codeception\Attribute\Group;
use Tests\Support\AcceptanceTester;
use Tests\Support\Page\Acceptance\Install;

class InstallCest
{
    public function _before(AcceptanceTester $I) {}

    #[Group('install', 'api', 'fr-ch-localization')]
    public function installPageWorks(AcceptanceTester $I): void
    {
        $I->amOnPage('/install');
        $I->waitForElementVisible('.registrationForm', 10);

        $I->see('Install');
    }

    #[Group('install', 'api', 'fr-ch-localization')]
    #[Depends('installPageWorks')]
    public function createDBSuccessfully(AcceptanceTester $I, Install $installPage): void
    {
        $installPage->install(
            'owner@julianna.test',
            'JuliannaTest123!',
            'John',
            'Smith',
            'Smith & Co'
        );
    }
}
