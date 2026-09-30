<?php

declare(strict_types=1);

namespace Leantime\Domain\IdeaRoom\Search;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use JsonException;

final class GoogleProgrammableSearchProvider implements SearchProvider
{
    private const ENDPOINT = 'https://customsearch.googleapis.com/customsearch/v1';

    private const MAX_BODY_BYTES = 262144;

    public function __construct(
        private readonly ClientInterface $http,
        private readonly string $apiKey,
        private readonly string $engineId,
        private readonly int $maxResults = 5,
        private readonly int $timeoutSeconds = 8,
    ) {}

    public function search(SearchRequest $request): SearchResponse
    {
        try {
            $response = $this->http->request('GET', self::ENDPOINT, [
                'query' => [
                    'key' => $this->apiKey,
                    'cx' => $this->engineId,
                    'q' => $request->query,
                    'num' => min($request->limit, max(1, min(10, $this->maxResults))),
                    'safe' => 'active',
                ],
                'headers' => ['Accept' => 'application/json'],
                'connect_timeout' => min(3, max(1, $this->timeoutSeconds)),
                'timeout' => max(1, min(15, $this->timeoutSeconds)),
                'allow_redirects' => false,
                'http_errors' => false,
            ]);

            if ($response->getStatusCode() !== 200) {
                throw new SearchException('Search is unavailable. Please try again.');
            }

            $stream = $response->getBody();
            $raw = '';
            while (! $stream->eof()) {
                $part = $stream->read(min(8192, self::MAX_BODY_BYTES - strlen($raw) + 1));
                if ($part === '' || strlen($raw) + strlen($part) > self::MAX_BODY_BYTES) {
                    throw new SearchException('Search returned an invalid response.');
                }
                $raw .= $part;
            }
            $body = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
            if (! is_array($body) || ! is_array($body['items'] ?? [])) {
                throw new SearchException('Search returned an invalid response.');
            }

            $searchedAt = gmdate('c');
            $results = [];
            $seen = [];
            foreach ($body['items'] ?? [] as $item) {
                if (count($results) >= min($request->limit, max(1, min(10, $this->maxResults)))) {
                    break;
                }
                $normalized = self::normalizeItem($item, $request->query, $searchedAt);
                if ($normalized === null || isset($seen[$normalized['url']])) {
                    continue;
                }
                $results[] = $normalized;
                $seen[$normalized['url']] = true;
            }

            return new SearchResponse($request->query, $results, 'google', $searchedAt);
        } catch (GuzzleException) {
            throw new SearchException('Search is unavailable. Please try again.');
        } catch (JsonException) {
            throw new SearchException('Search returned an invalid response.');
        }
    }

    /** @return array{url: string, title: string, snippet: string, domain: string, provider: string, query: string, searchedAt: string}|null */
    private static function normalizeItem(mixed $item, string $query, string $searchedAt): ?array
    {
        if (! is_array($item) || ! is_string($item['link'] ?? null)) {
            return null;
        }
        $url = trim($item['link']);
        if (strlen($url) > 2000 || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }
        $parts = parse_url($url);
        if (! is_array($parts) || ! in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (strlen($host) > 255 || ! str_contains($host, '.') || str_contains($host, '[') || str_contains($host, ']')
            || filter_var($host, FILTER_VALIDATE_IP) !== false
            || preg_match('/(?:^|\.)(?:localhost|local|internal|test|invalid|example|onion)$/', $host)) {
            return null;
        }

        return [
            'url' => $url,
            'title' => self::plainText($item['title'] ?? '', 240),
            'snippet' => self::plainText($item['snippet'] ?? '', 500),
            'domain' => $host,
            'provider' => 'google',
            'query' => $query,
            'searchedAt' => $searchedAt,
        ];
    }

    private static function plainText(mixed $value, int $maximum): string
    {
        if (! is_string($value)) {
            return '';
        }
        $text = strip_tags(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $text = preg_replace('/[\p{C}\s]+/u', ' ', $text);

        return mb_substr(trim(is_string($text) ? $text : ''), 0, $maximum);
    }
}
