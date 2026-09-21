<?php

use Leantime\Core\Events\EventDispatcher;
use Leantime\Domain\Auth\Listeners\ShowPersonalTokenContent;
use Leantime\Domain\Auth\Listeners\ShowPersonalTokenTab;

// Register Personal Access Tokens tab in user account settings
EventDispatcher::add_event_listener(
    'leantime.domain.users.templates.editOwn.tabs',
    ShowPersonalTokenTab::class
);

EventDispatcher::add_event_listener(
    'leantime.domain.users.templates.editOwn.tabsContent',
    ShowPersonalTokenContent::class
);
