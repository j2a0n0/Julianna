<?php

declare(strict_types=1);

namespace Leantime\Domain\Mcp\Services;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use InvalidArgumentException;
use Leantime\Domain\Agent\Services\WebSearchConfiguration;
use RuntimeException;

/** Read-only research through one fixed search endpoint; never fetch model-supplied URLs. */
final class WebSearch
{
    private const ENDPOINT = 'https://api.search.brave.com/res/v1/web/search';

    private const CONTEXT_ENDPOINT = 'https://api.search.brave.com/res/v1/llm/context';

    public function __construct(private readonly ?ClientInterface $http = null) {}

    public static function configured(): bool
    {
        return self::apiKey() !== '';
    }

    /** @return array{query:string,results:list<array<string,string>>,searchedAt:string} */
    public function search(string $query): array
    {
        $query = self::validatedQuery($query);
        $key = self::apiKey();
        if ($key === '') {
            throw new RuntimeException('Web search is not configured. Set JULIANNA_WEB_SEARCH_API_KEY on the server.');
        }

        try {
            $response = ($this->http ?? new Client)->request('GET', self::ENDPOINT, [
                'headers' => [
                    'Accept' => 'application/json',
                    'X-Subscription-Token' => $key,
                ],
                'query' => ['q' => $query, 'count' => 5, 'safesearch' => 'moderate'],
                'connect_timeout' => 3,
                'timeout' => 10,
                'allow_redirects' => false,
                'http_errors' => false,
                'proxy' => '',
            ]);
        } catch (GuzzleException) {
            throw new RuntimeException('Web search is temporarily unavailable.');
        }
        if ($response->getStatusCode() !== 200) {
            throw new RuntimeException($response->getStatusCode() === 429
                ? 'Web search rate limit reached. Please try again later.'
                : 'Web search is unavailable. Check the server-side search key.');
        }
        $body = $response->getBody();
        if ($body->getSize() !== null && $body->getSize() > 262144) {
            throw new RuntimeException('Web search returned an oversized response.');
        }
        $raw = $body->read(262145);
        if (strlen($raw) > 262144) {
            throw new RuntimeException('Web search returned an oversized response.');
        }
        $decoded = json_decode($raw, true);
        if (! is_array($decoded) || ! is_array($decoded['web']['results'] ?? null)) {
            throw new RuntimeException('Web search returned an invalid response.');
        }
        $results = [];
        foreach (array_slice($decoded['web']['results'], 0, 5) as $item) {
            if (! is_array($item) || ! is_string($item['url'] ?? null)
                || ! str_starts_with($item['url'], 'https://')) {
                continue;
            }
            $results[] = [
                'title' => self::clean((string) ($item['title'] ?? ''), 180),
                'url' => $item['url'],
                'snippet' => self::clean((string) ($item['description'] ?? ''), 600),
                'published' => self::clean((string) ($item['page_age'] ?? $item['age'] ?? ''), 80),
            ];
        }

        return ['query' => $query, 'results' => $results, 'searchedAt' => gmdate('c')];
    }

    /** @return array{query:string,sources:list<array{title:string,url:string,excerpts:list<string>}>,searchedAt:string} */
    public function research(string $query): array
    {
        $query = self::validatedQuery($query);
        $key = self::apiKey();
        if ($key === '') {
            throw new RuntimeException('Web research is not configured. Set JULIANNA_WEB_SEARCH_API_KEY on the server.');
        }
        try {
            $response = ($this->http ?? new Client)->request('POST', self::CONTEXT_ENDPOINT, [
                'headers' => ['Accept' => 'application/json', 'X-Subscription-Token' => $key],
                'json' => [
                    'q' => $query,
                    'count' => 5,
                    'maximum_number_of_urls' => 3,
                    'maximum_number_of_tokens' => 2048,
                    'maximum_number_of_snippets' => 9,
                    'safesearch' => 'moderate',
                    'enable_local' => false,
                ],
                'connect_timeout' => 3,
                'timeout' => 12,
                'allow_redirects' => false,
                'http_errors' => false,
                'proxy' => '',
            ]);
        } catch (GuzzleException) {
            throw new RuntimeException('Web research is temporarily unavailable.');
        }
        if ($response->getStatusCode() !== 200) {
            throw new RuntimeException('Web research is unavailable. Check the search API key and plan.');
        }
        $body = $response->getBody();
        if ($body->getSize() !== null && $body->getSize() > 262144) {
            throw new RuntimeException('Web research returned an oversized response.');
        }
        $raw = $body->read(262145);
        if (strlen($raw) > 262144) {
            throw new RuntimeException('Web research returned an oversized response.');
        }
        $decoded = json_decode($raw, true);
        if (! is_array($decoded) || ! is_array($decoded['grounding']['generic'] ?? null)) {
            throw new RuntimeException('Web research returned an invalid response.');
        }
        $sources = [];
        foreach (array_slice($decoded['grounding']['generic'], 0, 3) as $item) {
            if (! is_array($item) || ! is_string($item['url'] ?? null)
                || ! str_starts_with($item['url'], 'https://')) {
                continue;
            }
            $excerpts = [];
            foreach (array_slice(is_array($item['snippets'] ?? null) ? $item['snippets'] : [], 0, 3) as $snippet) {
                if (is_string($snippet)) {
                    $excerpts[] = self::clean($snippet, 1200);
                }
            }
            $sources[] = [
                'title' => self::clean((string) ($item['title'] ?? ''), 180),
                'url' => $item['url'],
                'excerpts' => $excerpts,
            ];
        }

        return ['query' => $query, 'sources' => $sources, 'searchedAt' => gmdate('c')];
    }

    private static function validatedQuery(string $query): string
    {
        $query = trim($query);
        if ($query === '' || mb_strlen($query) > 200 || preg_match('/[\x00-\x1F\x7F]/', $query) === 1) {
            throw new InvalidArgumentException('Enter a search query of at most 200 characters.');
        }
        if (preg_match('/\b[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}\b/i', $query) === 1
            || preg_match('/\b(?:bearer|api[_ -]?key|password|secret|token)\s*[:=]\s*\S+/i', $query) === 1) {
            throw new InvalidArgumentException('Web search queries cannot contain email addresses or credentials.');
        }

        return $query;
    }

    private static function apiKey(): string
    {
        return (new WebSearchConfiguration)->key();
    }

    private static function clean(string $value, int $limit): string
    {
        return mb_substr(trim(html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8')), 0, $limit);
    }
}
