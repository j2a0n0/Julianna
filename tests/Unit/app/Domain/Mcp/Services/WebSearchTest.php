<?php

declare(strict_types=1);

namespace Unit\app\Domain\Mcp\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use InvalidArgumentException;
use Leantime\Domain\Mcp\Services\WebSearch;
use PHPUnit\Framework\TestCase;

final class WebSearchTest extends TestCase
{
    private mixed $oldKey;

    protected function setUp(): void
    {
        parent::setUp();
        $this->oldKey = $_ENV['JULIANNA_WEB_SEARCH_API_KEY'] ?? null;
        $_ENV['JULIANNA_WEB_SEARCH_API_KEY'] = 'test-search-key';
    }

    protected function tearDown(): void
    {
        if ($this->oldKey === null) {
            unset($_ENV['JULIANNA_WEB_SEARCH_API_KEY']);
        } else {
            $_ENV['JULIANNA_WEB_SEARCH_API_KEY'] = $this->oldKey;
        }
        parent::tearDown();
    }

    public function test_search_uses_only_fixed_read_only_endpoint_and_returns_bounded_snippets(): void
    {
        $history = [];
        $handler = HandlerStack::create(new MockHandler([new Response(200, [], json_encode([
            'web' => ['results' => [
                ['title' => '<b>Example</b>', 'url' => 'https://example.org/article', 'description' => 'Fresh &amp; useful', 'age' => 'today'],
                ['title' => 'Unsafe', 'url' => 'http://internal.invalid/', 'description' => 'skip'],
            ]],
        ]))]));
        $handler->push(Middleware::history($history));
        $result = (new WebSearch(new Client(['handler' => $handler])))->search('restaurant ordering trends');

        self::assertSame('restaurant ordering trends', $result['query']);
        self::assertCount(1, $result['results']);
        self::assertSame('Example', $result['results'][0]['title']);
        self::assertSame('Fresh & useful', $result['results'][0]['snippet']);
        self::assertSame('GET', $history[0]['request']->getMethod());
        self::assertSame('api.search.brave.com', $history[0]['request']->getUri()->getHost());
        self::assertSame('test-search-key', $history[0]['request']->getHeaderLine('X-Subscription-Token'));
        self::assertSame('', $history[0]['options']['proxy']);
    }

    public function test_search_rejects_private_credentials_before_any_network_call(): void
    {
        $search = new WebSearch(new Client(['handler' => new MockHandler]));
        foreach (['alice@example.org', 'password: abc123', str_repeat('x', 201)] as $query) {
            try {
                $search->search($query);
                self::fail('A private or oversized query was accepted.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_research_returns_bounded_source_linked_extracts_without_fetching_a_url(): void
    {
        $history = [];
        $handler = HandlerStack::create(new MockHandler([new Response(200, [], json_encode([
            'grounding' => ['generic' => [[
                'url' => 'https://example.org/report',
                'title' => '<b>Public report</b>',
                'snippets' => ['First &amp; second', str_repeat('x', 1500), 'Third', 'Not included'],
            ]]],
        ]))]));
        $handler->push(Middleware::history($history));
        $result = (new WebSearch(new Client(['handler' => $handler])))->research('restaurant ordering market');

        self::assertSame('https://example.org/report', $result['sources'][0]['url']);
        self::assertSame('Public report', $result['sources'][0]['title']);
        self::assertSame('First & second', $result['sources'][0]['excerpts'][0]);
        self::assertCount(3, $result['sources'][0]['excerpts']);
        self::assertSame(1200, strlen($result['sources'][0]['excerpts'][1]));
        self::assertSame('POST', $history[0]['request']->getMethod());
        self::assertSame('api.search.brave.com', $history[0]['request']->getUri()->getHost());
        self::assertSame('/res/v1/llm/context', $history[0]['request']->getUri()->getPath());
        self::assertSame(3, json_decode((string) $history[0]['request']->getBody(), true)['maximum_number_of_urls']);
    }
}
