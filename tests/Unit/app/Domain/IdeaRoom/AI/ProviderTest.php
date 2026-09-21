<?php

declare(strict_types=1);

namespace Unit\app\Domain\IdeaRoom\AI;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Leantime\Domain\IdeaRoom\AI\AnthropicProvider;
use Leantime\Domain\IdeaRoom\AI\ChatRequest;
use Leantime\Domain\IdeaRoom\AI\OpenAiProvider;
use Leantime\Domain\IdeaRoom\AI\PlanPatch;
use Leantime\Domain\IdeaRoom\AI\ProviderException;
use Leantime\Domain\IdeaRoom\AI\ProviderFactory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class ProviderTest extends TestCase
{
    public function test_openai_adapter_sends_transcript_and_parses_plan_patch(): void
    {
        $history = [];
        $http = $this->mockHttp([
            new Response(200, [], json_encode([
                'choices' => [[
                    'finish_reason' => 'stop',
                    'message' => ['content' => json_encode([
                        'text' => 'Voici un premier pas.',
                        'plan_patch' => ['outcome' => '  Lancer un atelier  ', 'tasks' => [['title' => 'Inviter trois personnes']]],
                    ])],
                ]],
            ])),
        ], $history);

        $reply = (new OpenAiProvider($http, 'private-key', 'test-model'))->respond($this->request());

        self::assertSame('Voici un premier pas.', $reply->text);
        self::assertSame('Lancer un atelier', $reply->planPatch['outcome']);
        self::assertSame([['title' => 'Inviter trois personnes', 'description' => '']], $reply->planPatch['tasks']);
        self::assertCount(1, $history);
        /** @var RequestInterface $sent */
        $sent = $history[0]['request'];
        self::assertSame('Bearer private-key', $sent->getHeaderLine('Authorization'));
        self::assertSame('/v1/chat/completions', $sent->getUri()->getPath());
        $body = json_decode((string) $sent->getBody(), true);
        self::assertSame('test-model', $body['model']);
        self::assertSame('user', $body['messages'][3]['role']);
        self::assertSame('Comment commencer ?', $body['messages'][3]['content']);
    }

    public function test_anthropic_adapter_uses_messages_api_and_parses_patch(): void
    {
        $history = [];
        $http = $this->mockHttp([
            new Response(200, [], json_encode([
                'stop_reason' => 'end_turn',
                'content' => [['type' => 'text', 'text' => json_encode([
                    'text' => 'Précisons le budget.',
                    'plan_patch' => ['openQuestions' => ['Quel budget ?']],
                ])]],
            ])),
        ], $history);

        $reply = (new AnthropicProvider($http, 'private-key', 'test-model'))->respond($this->request());

        self::assertSame(['Quel budget ?'], $reply->planPatch['openQuestions']);
        /** @var RequestInterface $sent */
        $sent = $history[0]['request'];
        self::assertSame('private-key', $sent->getHeaderLine('x-api-key'));
        self::assertSame('/v1/messages', $sent->getUri()->getPath());
        $body = json_decode((string) $sent->getBody(), true);
        self::assertSame('test-model', $body['model']);
        self::assertCount(2, $body['messages']);
        self::assertSame('assistant', $body['messages'][0]['role']);
    }

    public function test_malformed_provider_response_is_rejected_without_exposing_body(): void
    {
        $http = $this->mockHttp([new Response(200, [], json_encode([
            'choices' => [[
                'finish_reason' => 'stop',
                'message' => ['content' => '{"text":"hello","plan_patch":{"execute":"DROP TABLE"}}'],
            ]],
        ]))]);

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('AI provider returned an invalid response.');
        (new OpenAiProvider($http, 'private-key', 'test-model'))->respond($this->request());
    }

    public function test_provider_error_and_truncated_response_are_rejected(): void
    {
        $http = $this->mockHttp([new Response(503, [], 'secret provider error')]);
        try {
            (new AnthropicProvider($http, 'private-key', 'test-model'))->respond($this->request());
            self::fail('Expected provider failure.');
        } catch (ProviderException $e) {
            self::assertSame('AI provider is unavailable. Please try again.', $e->getMessage());
            self::assertStringNotContainsString('secret provider error', $e->getMessage());
        }

        $http = $this->mockHttp([new Response(200, [], json_encode([
            'stop_reason' => 'max_tokens',
            'content' => [['type' => 'text', 'text' => '{}']],
        ]))]);
        $this->expectException(ProviderException::class);
        (new AnthropicProvider($http, 'private-key', 'test-model'))->respond($this->request());
    }

    public function test_patch_bounds_and_configuration_gate(): void
    {
        self::assertNull(ProviderFactory::fromConfiguration('', '', ''));
        self::assertNull(ProviderFactory::fromConfiguration('openai', '', 'model'));
        self::assertNull(ProviderFactory::fromConfiguration('unknown', 'key', 'model'));
        self::assertInstanceOf(OpenAiProvider::class, ProviderFactory::fromConfiguration('openai', 'key', 'model'));
        self::assertInstanceOf(AnthropicProvider::class, ProviderFactory::fromConfiguration('anthropic', 'key', 'model'));

        $this->expectException(\InvalidArgumentException::class);
        PlanPatch::validate(['milestones' => array_fill(0, 31, ['title' => 'Too many'])]);
    }

    private function request(): ChatRequest
    {
        return new ChatRequest(
            messages: [
                ['role' => 'assistant', 'content' => 'Parlez-moi de votre idée.'],
                ['role' => 'user', 'content' => 'Comment commencer ?'],
            ],
            currentPlan: ['outcome' => ''],
            locale: 'fr-CH',
        );
    }

    /** @param array<int, Response> $responses
     * @param  array<int, mixed>  $history
     */
    private function mockHttp(array $responses, array &$history = []): Client
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($history));

        return new Client(['handler' => $stack]);
    }
}
