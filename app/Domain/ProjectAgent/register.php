<?php

declare(strict_types=1);

namespace Leantime\Domain\ProjectAgent;

use Illuminate\Console\Scheduling\Schedule;
use Leantime\Core\Events\EventDispatcher;
use Leantime\Domain\ProjectAgent\Services\ProjectAgent;
use Leantime\Domain\Tickets\Events\TicketCreated;
use Leantime\Domain\Tickets\Events\TicketUpdated;

EventDispatcher::add_event_listener('leantime.core.console.consolekernel.schedule.cron', static function (array $params): void {
    $schedule = $params['schedule'] ?? null;
    if (! $schedule instanceof Schedule) {
        return;
    }
    $schedule->call(static fn (): int => app()->make(ProjectAgent::class)->enqueueDailyReviews())
        ->name('project-agent:daily')->daily();
});

EventDispatcher::add_event_listener(TicketCreated::class, static function (TicketCreated $event): void {
    if ($event->ticketId !== null && ! ProjectAgent::agentActionInProgress()) {
        app()->make(ProjectAgent::class)->enqueueTicketEvent($event->ticketId, 'created');
    }
});

EventDispatcher::add_event_listener(TicketUpdated::class, static function (TicketUpdated $event): void {
    if ($event->ticketId !== null && ! ProjectAgent::agentActionInProgress()) {
        app()->make(ProjectAgent::class)->enqueueTicketEvent($event->ticketId, 'updated');
    }
});
