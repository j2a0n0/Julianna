<?php

declare(strict_types=1);

namespace Leantime\Domain\IdeaRoom\Search;

final class SearchResponse
{
    /**
     * @param  array<int, array{url: string, title: string, snippet: string, domain: string, provider: string, query: string, searchedAt: string}>  $results
     */
    public function __construct(
        public readonly string $query,
        public readonly array $results,
        public readonly string $provider,
        public readonly string $searchedAt,
    ) {}

    /** @return array{query: string, results: array<int, array<string, string>>, provider: string, searchedAt: string} */
    public function toArray(): array
    {
        return [
            'query' => $this->query,
            'results' => $this->results,
            'provider' => $this->provider,
            'searchedAt' => $this->searchedAt,
        ];
    }
}
