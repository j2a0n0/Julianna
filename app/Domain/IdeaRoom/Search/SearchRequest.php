<?php

declare(strict_types=1);

namespace Leantime\Domain\IdeaRoom\Search;

use InvalidArgumentException;

final class SearchRequest
{
    public readonly string $query;

    public function __construct(string $query, public readonly int $limit = 5)
    {
        $normalized = preg_replace('/\s+/u', ' ', trim($query));
        if (! is_string($normalized) || $normalized === '' || mb_strlen($normalized) > 300) {
            throw new InvalidArgumentException('Search query must contain 1–300 characters.');
        }
        if ($limit < 1 || $limit > 10) {
            throw new InvalidArgumentException('Search result limit must be between 1 and 10.');
        }

        $this->query = $normalized;
    }
}
