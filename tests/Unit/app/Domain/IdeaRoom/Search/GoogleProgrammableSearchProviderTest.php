<?php

declare(strict_types=1);

namespace Unit\app\Domain\IdeaRoom\Search;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request as HttpRequest;
use GuzzleHttp\Psr7\Response;
use InvalidArgumentException;
use Leantime\Domain\IdeaRoom\Search\GoogleProgrammableSearchProvider;
use Leantime\Domain\IdeaRoom\Search\SearchException;
use Leantime\Domain\IdeaRoom\Search\SearchProviderFactory;
use Leantime\Domain\IdeaRoom\Search\SearchRequest;
use PHPUnit\Framework\TestCase;

final class GoogleProgrammableSearchProviderTest extends TestCase
{
    public function test_google_search_normalizes_only_citation_metadata_and_bounds_results(): void
    {
        $history = [];
        $http = $this->mockHttp([new Response(200, [], json_encode(['items' => [
            ['link' => 'https://news.example.org/article', 'title' => '  <b>Useful</b> &amp; cited  ', 'snippet' => "First\n  source"],
            ['link' => 'https://news.example.org/article', 'title' => 'Duplicate', 'snippet' => 'Omitted'],
            ['link' => 'javascript:alert(1)', 'title' => 'Unsafe', 'snippet' => 'Omitted'],
            ['link' => 'http://127.0.0.1/private', 'title' => 'Internal', 'snippet' => 'Omitted'],
            ['link' => 'http://[::ffff:127.0.0.1]/private', 'title' => 'IPv6 internal', 'snippet' => 'Omitted'],
            ['link' => 'https://user:pass@external.example.org/path', 'title' => 'Credentials', 'snippet' => 'Omitted'],
            ['link' => 'https://valid.example.com/second', 'title' => 'Second', 'snippet' => 'Kept'],
            ['link' => 'https://valid.example.com/third', 'title' => 'Third', 'snippet' => 'Capped'],
        ]]))], $history);

        $provider = new GoogleProgrammableSearchProvider($http, 'private-api-key', 'engine-id', 2, 4);
        $result = $provider->search(new SearchRequest('  sustainable   design  ', 10));

        self::assertSame('sustainable design', $result->query);
        self::assertSame('google', $result->provider);
        self::assertCount(2, $result->results);
        self::assertSame([
            'url' => 'https://news.example.org/article',
            'title' => 'Useful & cited',
            'snippet' => 'First source',
            'domain' => 'news.example.org',
            'provider' => 'google',
            'query' => 'sustainable design',
            'searchedAt' => $result->searchedAt,
        ], $result->results[0]);
        self::assertSame($result->results, $result->toArray()['results']);
        self::assertCount(1, $history); // Never fetches result pages.
        $sent = $history[0]['request'];
        self::assertSame('customsearch.googleapis.com', $sent->getUri()->getHost());
        self::assertSame('/customsearch/v1', $sent->getUri()->getPath());
        parse_str($sent->getUri()->getQuery(), $parameters);
        self::assertSame('private-api-key', $parameters['key']);
        self::assertSame('engine-id', $parameters['cx']);
        self::assertSame('sustainable design', $parameters['q']);
        self::assertSame('2', $parameters['num']);
    }

    public function test_factory_requires_complete_configuration_and_clamps_limits(): void
    {
        self::assertNull(SearchProviderFactory::fromConfiguration('', 'key', 'engine'));
        self::assertNull(SearchProviderFactory::fromConfiguration('google', '', 'engine'));
        self::assertNull(SearchProviderFactory::fromConfiguration('google', 'key', ''));
        self::assertNull(SearchProviderFactory::fromConfiguration('unknown', 'key', 'engine'));

        $history = [];
        $http = $this->mockHttp([new Response(200, [], '{}')], $history);
        $provider = SearchProviderFactory::fromConfiguration(' GOOGLE ', ' key ', ' engine ', 99, 99, $http);
        self::assertNotNull($provider);
        $provider->search(new SearchRequest('test', 10));
        parse_str($history[0]['request']->getUri()->getQuery(), $parameters);
        self::assertSame('10', $parameters['num']);
    }

    public function test_factory_clamps_timeout_and_never_allows_redirects(): void
    {
        $http = $this->createMock(ClientInterface::class);
        $http->expects(self::once())->method('request')
            ->with('GET', 'https://customsearch.googleapis.com/customsearch/v1', self::callback(static function (array $options): bool {
                return $options['timeout'] === 15
                    && $options['connect_timeout'] === 3
                    && $options['allow_redirects'] === false
                    && $options['http_errors'] === false;
            }))
            ->willReturn(new Response(200, [], '{}'));

        $provider = SearchProviderFactory::fromConfiguration('google', 'key', 'engine', 5, 99, $http);
        self::assertNotNull($provider);
        self::assertSame([], $provider->search(new SearchRequest('query'))->results);
    }

    public function test_query_validation_rejects_empty_too_long_invalid_utf8_and_result_overflow(): void
    {
        foreach ([[' ', 5], [str_repeat('a', 301), 5], ["\xff", 5], ['valid', 0], ['valid', 11]] as [$query, $limit]) {
            try {
                new SearchRequest($query, $limit);
                self::fail('Invalid search request was accepted.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_http_failure_timeout_and_malformed_responses_have_generic_errors(): void
    {
        foreach ([
            new Response(403, [], 'private-api-key: bad credentials'),
            new Response(200, [], '{invalid json'),
            new Response(200, [], str_repeat('X', 262145)),
            new ConnectException('private-api-key: timeout', new HttpRequest('GET', 'https://customsearch.googleapis.com/customsearch/v1')),
        ] as $response) {
            $http = $this->mockHttp([$response]);
            try {
                (new GoogleProgrammableSearchProvider($http, 'private-api-key', 'engine'))->search(new SearchRequest('query'));
                self::fail('Expected search failure.');
            } catch (SearchException $exception) {
                self::assertStringNotContainsString('private-api-key', $exception->getMessage());
                self::assertStringNotContainsString('bad credentials', $exception->getMessage());
            }
        }
    }

    /**
     * @param  array<int, Response|\Throwable>  $responses
     * @param  array<int, mixed>  $history
     */
    private function mockHttp(array $responses, array &$history = []): Client
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($history));

        return new Client(['handler' => $stack]);
    }
}
