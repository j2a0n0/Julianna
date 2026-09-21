<?php

namespace Leantime\Domain\Notifications\Services;

/**
 * Compatibility stub for the removed upstream product-news integration.
 * Project notifications remain local and are handled by Notifications.
 */
final class News
{
    public function getLatest(int $userId): false
    {
        return false;
    }

    public function hasNews(int $userId): bool
    {
        return false;
    }

    public function getFeed(): false
    {
        return false;
    }
}
