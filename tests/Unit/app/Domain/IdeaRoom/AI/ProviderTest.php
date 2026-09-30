<?php

declare(strict_types=1);

namespace Unit\app\Domain\IdeaRoom\AI;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Leantime\Domain\IdeaRoom\AI\AnthropicProvider;
use Leantime\Domain\IdeaRoom\AI\CanvasPatch;
use Leantime\Domain\IdeaRoom\AI\ChatRequest;
use Leantime\Domain\IdeaRoom\AI\OpenAiProvider;
use Leantime\Domain\IdeaRoom\AI\PlanPatch;
use Leantime\Domain\IdeaRoom\AI\ProviderException;
use Leantime\Domain\IdeaRoom\AI\ProviderFactory;
use Leantime\Domain\IdeaRoom\AI\ToolCall;
use Leantime\Domain\IdeaRoom\AI\ToolDefinition;
use Leantime\Domain\IdeaRoom\AI\ToolResult;
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

    public function test_deepseek_and_kimi_use_their_own_endpoints_without_leaking_keys(): void
    {
        foreach ([
            ['deepseek', 'api.deepseek.com', '/chat/completions', true],
            ['kimi', 'api.moonshot.ai', '/v1/chat/completions', false],
        ] as [$provider, $host, $path, $jsonMode]) {
            $history = [];
            $http = $this->mockHttp([new Response(200, [], json_encode([
                'choices' => [[
                    'finish_reason' => 'stop',
                    'message' => ['content' => json_encode(['text' => 'Prêt.', 'plan_patch' => []])],
                ]],
            ]))], $history);

            $adapter = ProviderFactory::fromConfiguration($provider, 'private-key', 'selected-model', $http);
            self::assertNotNull($adapter);
            self::assertSame('Prêt.', $adapter->respond($this->request())->text);
            /** @var RequestInterface $sent */
            $sent = $history[0]['request'];
            self::assertSame($host, $sent->getUri()->getHost());
            self::assertSame($path, $sent->getUri()->getPath());
            self::assertSame('Bearer private-key', $sent->getHeaderLine('Authorization'));
            $body = json_decode((string) $sent->getBody(), true);
            self::assertSame('selected-model', $body['model']);
            if ($provider === 'deepseek') {
                self::assertSame(['type' => 'disabled'], $body['thinking']);
            } else {
                self::assertArrayNotHasKey('thinking', $body);
            }
            self::assertSame($jsonMode, isset($body['response_format']));
        }
    }

    public function test_openai_compatible_stream_emits_real_text_deltas_and_validates_done(): void
    {
        $frames = '';
        foreach ([
            ['choices' => [['delta' => ['content' => 'Bon'], 'finish_reason' => null]]],
            ['choices' => [['delta' => ['content' => 'jour'], 'finish_reason' => null]]],
            ['choices' => [['delta' => [], 'finish_reason' => 'stop']]],
        ] as $chunk) {
            $frames .= 'data: '.json_encode($chunk)."\n\n";
        }
        $frames .= "data: [DONE]\n\n";
        $history = [];
        $http = $this->mockHttp([new Response(200, ['Content-Type' => 'text/event-stream'], $frames)], $history);
        $deltas = [];

        $turn = (new OpenAiProvider($http, 'private-key', 'model'))->streamTurn(
            $this->request(),
            static function (string $delta) use (&$deltas): void {
                $deltas[] = $delta;
            },
        );

        self::assertSame(['Bon', 'jour'], $deltas);
        self::assertSame('Bonjour', $turn->text);
        self::assertSame('completed', $turn->finishReason);
        $body = json_decode((string) $history[0]['request']->getBody(), true);
        self::assertTrue($body['stream']);
    }

    public function test_streamed_provider_failure_shows_status_without_exposing_response_body(): void
    {
        $http = $this->mockHttp([new Response(400, [], 'private provider diagnostics')]);

        try {
            (new OpenAiProvider($http, 'private-key', 'test-model'))->streamTurn($this->toolRequest(), static function (): void {});
            self::fail('Expected the provider request to fail.');
        } catch (ProviderException $error) {
            self::assertStringContainsString('HTTP 400', $error->getMessage());
            self::assertStringNotContainsString('private provider diagnostics', $error->getMessage());
            self::assertStringNotContainsString('private-key', $error->getMessage());
        }
    }

    public function test_non_streaming_provider_failure_keeps_only_http_status(): void
    {
        $http = $this->mockHttp([new Response(401, [], 'private provider diagnostics')]);

        try {
            (new OpenAiProvider($http, 'private-key', 'test-model'))->turn($this->toolRequest());
            self::fail('Expected the provider request to fail.');
        } catch (ProviderException $error) {
            self::assertSame(401, $error->getCode());
            self::assertStringContainsString('HTTP 401', $error->getMessage());
            self::assertStringNotContainsString('private provider diagnostics', $error->getMessage());
            self::assertStringNotContainsString('private-key', $error->getMessage());
        }
    }

    public function test_gpt_6_luna_tool_requests_disable_reasoning_on_chat_completions(): void
    {
        $history = [];
        $http = $this->mockHttp([new Response(200, [], json_encode([
            'choices' => [['finish_reason' => 'stop', 'message' => ['content' => 'OK']]],
        ]))], $history);
        (new OpenAiProvider($http, 'private-key', 'gpt-6-luna'))->turn($this->toolRequest());

        $payload = json_decode((string) $history[0]['request']->getBody(), true);
        self::assertSame('none', $payload['reasoning_effort']);
        self::assertNotEmpty($payload['tools']);

        $history = [];
        $http = $this->mockHttp([new Response(200, [], json_encode([
            'choices' => [['finish_reason' => 'stop', 'message' => ['content' => 'OK']]],
        ]))], $history);
        (new OpenAiProvider($http, 'private-key', 'gpt-4.1'))->turn($this->toolRequest());
        $otherPayload = json_decode((string) $history[0]['request']->getBody(), true);
        self::assertArrayNotHasKey('reasoning_effort', $otherPayload);
    }

    public function test_gpt_6_luna_streaming_tool_request_uses_the_same_reasoning_setting(): void
    {
        $frames = 'data: '.json_encode(['choices' => [['delta' => ['content' => 'OK'], 'finish_reason' => null]]])."\n\n";
        $frames .= 'data: '.json_encode(['choices' => [['delta' => [], 'finish_reason' => 'stop']]])."\n\n";
        $frames .= "data: [DONE]\n\n";
        $history = [];
        $http = $this->mockHttp([new Response(200, ['Content-Type' => 'text/event-stream'], $frames)], $history);
        $turn = (new OpenAiProvider($http, 'private-key', 'gpt-6-luna'))->streamTurn(
            $this->toolRequest(), static function (): void {},
        );

        self::assertSame('OK', $turn->text);
        $payload = json_decode((string) $history[0]['request']->getBody(), true);
        self::assertSame('none', $payload['reasoning_effort']);
    }

    public function test_kimi_k3_preserves_reasoning_between_tool_turns(): void
    {
        $frames = '';
        foreach ([
            ['choices' => [['delta' => ['reasoning_content' => 'I should inspect ', 'content' => 'Je vérifie.'], 'finish_reason' => null]]],
            ['choices' => [['delta' => ['reasoning_content' => 'the projects.', 'tool_calls' => [[
                'index' => 0, 'id' => 'call_1', 'function' => ['name' => 'list_projects', 'arguments' => '{}'],
            ]]], 'finish_reason' => null]]],
            ['choices' => [['delta' => [], 'finish_reason' => 'tool_calls']]],
        ] as $chunk) {
            $frames .= 'data: '.json_encode($chunk)."\n\n";
        }
        $frames .= "data: [DONE]\n\n";
        $history = [];
        $http = $this->mockHttp([
            new Response(200, ['Content-Type' => 'text/event-stream'], $frames),
            new Response(200, [], json_encode(['choices' => [[
                'finish_reason' => 'stop', 'message' => ['content' => 'Voici les projets.'],
            ]]])),
        ], $history);
        $provider = ProviderFactory::fromConfiguration('kimi', 'private-key', 'kimi-k3', $http);
        $request = $this->toolRequest();
        $turn = $provider->streamTurn($request, static function (): void {});

        self::assertSame('I should inspect the projects.', $turn->reasoningContent);
        $continuation = new ChatRequest([
            ...$request->messages,
            $turn->asMessage(),
            (new ToolResult('call_1', 'list_projects', '{"projects":[]}'))->asMessage(),
        ], tools: $request->tools);
        $provider->turn($continuation);
        $sent = json_decode((string) $history[1]['request']->getBody(), true);
        self::assertSame('I should inspect the projects.', $sent['messages'][3]['reasoning_content']);
        self::assertArrayNotHasKey('thinking', $sent);
    }

    public function test_streamed_tool_arguments_are_assembled_before_validation(): void
    {
        $definition = new \Leantime\Domain\IdeaRoom\AI\ToolDefinition('getProject', 'Get project.', [
            'type' => 'object', 'properties' => ['projectId' => ['type' => 'integer']], 'required' => ['projectId'],
        ]);
        $request = new ChatRequest([['role' => 'user', 'content' => 'Find project one.']], tools: [$definition]);
        $frames = '';
        foreach ([
            ['choices' => [['delta' => ['tool_calls' => [['index' => 0, 'id' => 'call-1', 'function' => ['name' => 'getProject', 'arguments' => '{"project']]]], 'finish_reason' => null]]],
            ['choices' => [['delta' => ['tool_calls' => [['index' => 0, 'function' => ['arguments' => 'Id":1}']]]], 'finish_reason' => null]]],
            ['choices' => [['delta' => [], 'finish_reason' => 'tool_calls']]],
        ] as $chunk) {
            $frames .= 'data: '.json_encode($chunk)."\n\n";
        }
        $frames .= "data: [DONE]\n\n";
        $http = $this->mockHttp([new Response(200, ['Content-Type' => 'text/event-stream'], $frames)]);

        $turn = (new OpenAiProvider($http, 'private-key', 'model'))->streamTurn($request, static function (): void {});

        self::assertSame('tool_calls', $turn->finishReason);
        self::assertSame(['projectId' => 1], $turn->toolCalls[0]->arguments);
    }

    public function test_deepseek_stream_keeps_tool_identity_across_empty_and_repeated_continuations(): void
    {
        $frames = '';
        foreach ([
            ['choices' => [['delta' => ['tool_calls' => [[
                'index' => 0, 'id' => 'call_1', 'type' => 'function',
                'function' => ['name' => 'list_projects', 'arguments' => ''],
            ]]], 'finish_reason' => null]]],
            ['choices' => [['delta' => ['tool_calls' => [[
                'index' => 0, 'id' => '', 'function' => ['name' => '', 'arguments' => '{"lim'],
            ]]], 'finish_reason' => null]]],
            ['choices' => [['delta' => ['tool_calls' => [[
                'index' => 0, 'id' => null, 'function' => ['name' => null, 'arguments' => 'it":'],
            ]]], 'finish_reason' => null]]],
            ['choices' => [['delta' => ['tool_calls' => [[
                'index' => 0, 'id' => 'call_1', 'function' => ['name' => 'list_projects', 'arguments' => '5}'],
            ]]], 'finish_reason' => null]]],
            ['choices' => [['delta' => [], 'finish_reason' => 'tool_calls']]],
        ] as $chunk) {
            $frames .= 'data: '.json_encode($chunk)."\n\n";
        }
        $frames .= "data: [DONE]\n\n";
        $http = $this->mockHttp([new Response(200, ['Content-Type' => 'text/event-stream'], $frames)]);
        $provider = ProviderFactory::fromConfiguration('deepseek', 'private-key', 'deepseek-chat', $http);
        self::assertNotNull($provider);

        $turn = $provider->streamTurn($this->toolRequest(), static function (): void {});

        self::assertSame('call_1', $turn->toolCalls[0]->id);
        self::assertSame('list_projects', $turn->toolCalls[0]->name);
        self::assertSame(['limit' => 5], $turn->toolCalls[0]->arguments);
    }

    public function test_streamed_tool_identity_conflicts_still_fail_closed(): void
    {
        foreach ([
            ['id' => 'different_call'],
            ['function' => ['name' => 'other_tool']],
        ] as $conflict) {
            $frames = '';
            foreach ([
                ['choices' => [['delta' => ['tool_calls' => [[
                    'index' => 0, 'id' => 'call_1', 'function' => ['name' => 'list_projects', 'arguments' => ''],
                ]]], 'finish_reason' => null]]],
                ['choices' => [['delta' => ['tool_calls' => [[
                    'index' => 0, ...$conflict,
                ]]], 'finish_reason' => null]]],
                ['choices' => [['delta' => [], 'finish_reason' => 'tool_calls']]],
            ] as $chunk) {
                $frames .= 'data: '.json_encode($chunk)."\n\n";
            }
            $frames .= "data: [DONE]\n\n";
            $http = $this->mockHttp([new Response(200, ['Content-Type' => 'text/event-stream'], $frames)]);
            try {
                (new OpenAiProvider($http, 'private-key', 'model'))->streamTurn($this->toolRequest(), static function (): void {});
                self::fail('Expected a conflicting tool identity to be rejected.');
            } catch (ProviderException $error) {
                self::assertSame('AI provider returned an invalid tool call (conflicting identity).', $error->getMessage());
            }
        }
    }

    public function test_streamed_tool_rejection_reports_only_a_safe_category(): void
    {
        foreach ([
            [['index' => '0', 'id' => 'call_1', 'function' => ['name' => 'list_projects', 'arguments' => '{}']], 'invalid stream index'],
            [['index' => 0, 'function' => ['name' => 'list_projects', 'arguments' => '{}']], 'missing identity'],
            [['index' => 0, 'id' => 'call_1', 'function' => ['name' => 'delete_everything', 'arguments' => '{}']], 'unknown tool name'],
            [['index' => 0, 'id' => 'call_1', 'function' => ['name' => 'list_projects', 'arguments' => '{"limit":']], 'malformed arguments'],
        ] as [$part, $category]) {
            $frames = 'data: '.json_encode(['choices' => [[
                'delta' => ['tool_calls' => [$part]], 'finish_reason' => null,
            ]]])."\n\n";
            $frames .= 'data: '.json_encode(['choices' => [[
                'delta' => [], 'finish_reason' => 'tool_calls',
            ]]])."\n\n";
            $frames .= "data: [DONE]\n\n";
            $http = $this->mockHttp([new Response(200, ['Content-Type' => 'text/event-stream'], $frames)]);
            try {
                (new OpenAiProvider($http, 'private-key', 'model'))->streamTurn($this->toolRequest(), static function (): void {});
                self::fail('Expected malformed streamed tool call to be rejected.');
            } catch (ProviderException $error) {
                self::assertStringContainsString($category, $error->getMessage());
                self::assertStringNotContainsString('private-key', $error->getMessage());
                self::assertStringNotContainsString('delete_everything', $error->getMessage());
                self::assertStringNotContainsString('{"limit":', $error->getMessage());
            }
        }
    }

    public function test_anthropic_stream_assembles_tool_use_and_rejects_truncation(): void
    {
        $definition = new \Leantime\Domain\IdeaRoom\AI\ToolDefinition('getProject', 'Get project.', [
            'type' => 'object', 'properties' => ['projectId' => ['type' => 'integer']], 'required' => ['projectId'],
        ]);
        $request = new ChatRequest([['role' => 'user', 'content' => 'Find project one.']], tools: [$definition]);
        $frames = '';
        foreach ([
            ['content_block_start', ['index' => 0, 'content_block' => ['type' => 'text', 'text' => '']]],
            ['content_block_delta', ['index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'Je vérifie.']]],
            ['content_block_start', ['index' => 1, 'content_block' => ['type' => 'tool_use', 'id' => 'tool-1', 'name' => 'getProject', 'input' => []]]],
            ['content_block_delta', ['index' => 1, 'delta' => ['type' => 'input_json_delta', 'partial_json' => '{"projectId":1}']]],
            ['message_delta', ['delta' => ['stop_reason' => 'tool_use']]],
            ['message_stop', []],
        ] as [$event, $data]) {
            $frames .= "event: {$event}\n".'data: '.json_encode($data)."\n\n";
        }
        $http = $this->mockHttp([new Response(200, ['Content-Type' => 'text/event-stream'], $frames)]);
        $deltas = [];
        $turn = (new AnthropicProvider($http, 'private-key', 'model'))->streamTurn(
            $request,
            static function (string $delta) use (&$deltas): void {
                $deltas[] = $delta;
            },
        );
        self::assertSame(['Je vérifie.'], $deltas);
        self::assertSame(['projectId' => 1], $turn->toolCalls[0]->arguments);

        $truncated = $this->mockHttp([new Response(200, ['Content-Type' => 'text/event-stream'], "event: message_delta\ndata: {}\n\n")]);
        $this->expectException(ProviderException::class);
        (new AnthropicProvider($truncated, 'private-key', 'model'))->streamTurn($request, static function (): void {});
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
        self::assertInstanceOf(OpenAiProvider::class, ProviderFactory::fromConfiguration('deepseek', 'key', 'model'));
        self::assertInstanceOf(OpenAiProvider::class, ProviderFactory::fromConfiguration('kimi', 'key', 'model'));

        $this->expectException(\InvalidArgumentException::class);
        PlanPatch::validate(['milestones' => array_fill(0, 31, ['title' => 'Too many'])]);
    }

    public function test_openai_compatible_providers_send_tool_schemas_and_parse_calls(): void
    {
        foreach (['openai', 'deepseek', 'kimi'] as $provider) {
            $history = [];
            $http = $this->mockHttp([new Response(200, [], json_encode([
                'choices' => [[
                    'finish_reason' => 'tool_calls',
                    'message' => [
                        'content' => 'Je vérifie vos projets.',
                        'tool_calls' => [[
                            'id' => 'call_123',
                            'type' => 'function',
                            'function' => ['name' => 'list_projects', 'arguments' => '{"limit":5}'],
                        ]],
                    ],
                ]],
            ]))], $history);

            $adapter = ProviderFactory::fromConfiguration($provider, 'private-key', 'selected-model', $http);
            self::assertNotNull($adapter);
            $turn = $adapter->turn($this->toolRequest());

            self::assertSame('tool_calls', $turn->finishReason);
            self::assertSame('Je vérifie vos projets.', $turn->text);
            self::assertCount(1, $turn->toolCalls);
            self::assertSame('call_123', $turn->toolCalls[0]->id);
            self::assertSame('list_projects', $turn->toolCalls[0]->name);
            self::assertSame(['limit' => 5], $turn->toolCalls[0]->arguments);
            $sent = $history[0]['request'];
            $body = json_decode((string) $sent->getBody(), true);
            self::assertSame('auto', $body['tool_choice']);
            self::assertSame('list_projects', $body['tools'][0]['function']['name']);
            self::assertSame('object', $body['tools'][0]['function']['parameters']['type']);
            self::assertArrayNotHasKey('response_format', $body);
            self::assertStringContainsString('Idea Room canvas and plan changes may apply immediately', $body['messages'][0]['content']);
            self::assertStringContainsString('Every change to projects, goals, milestones, tasks', $body['messages'][0]['content']);
            self::assertStringNotContainsString('Return exactly one JSON object', $body['messages'][0]['content']);
            self::assertStringNotContainsString('private-key', (string) $sent->getBody());
        }
    }

    public function test_canvas_node_schema_reaches_every_configured_provider(): void
    {
        foreach (['openai', 'deepseek', 'kimi', 'anthropic'] as $name) {
            $history = [];
            $response = $name === 'anthropic'
                ? ['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => 'Ready.']]]
                : ['choices' => [['finish_reason' => 'stop', 'message' => ['content' => 'Ready.']]]];
            $http = $this->mockHttp([new Response(200, [], json_encode($response))], $history);
            $provider = ProviderFactory::fromConfiguration($name, 'private-key', 'test-model', $http);
            self::assertNotNull($provider);
            $provider->turn(new ChatRequest(
                [['role' => 'user', 'content' => 'Suggest a canvas idea']],
                tools: [CanvasPatch::toolDefinition()],
            ));

            $body = json_decode((string) $history[0]['request']->getBody(), true);
            $schema = $name === 'anthropic'
                ? $body['tools'][0]['input_schema']
                : $body['tools'][0]['function']['parameters'];
            $node = $schema['properties']['patch']['properties']['nodes']['items'];
            self::assertSame(false, $node['additionalProperties']);
            self::assertArrayHasKey('clientId', $node['properties']);
            self::assertArrayNotHasKey('text', $node['properties']);
            self::assertSame(['idea', 'question', 'insight', 'source', 'decision', 'next_step'], $node['properties']['type']['enum']);
            self::assertStringNotContainsString('private-key', (string) $history[0]['request']->getBody());
        }
    }

    public function test_anthropic_tool_use_and_continuation_mapping(): void
    {
        $history = [];
        $http = $this->mockHttp([
            new Response(200, [], json_encode([
                'stop_reason' => 'tool_use',
                'content' => [
                    ['type' => 'text', 'text' => 'Je cherche.'],
                    ['type' => 'tool_use', 'id' => 'toolu_123', 'name' => 'list_projects', 'input' => ['limit' => 5]],
                ],
            ])),
            new Response(200, [], json_encode([
                'stop_reason' => 'end_turn',
                'content' => [['type' => 'text', 'text' => 'Voici vos projets.']],
            ])),
        ], $history);

        $provider = new AnthropicProvider($http, 'private-key', 'test-model');
        $request = $this->toolRequest();
        $turn = $provider->turn($request);
        self::assertSame('tool_calls', $turn->finishReason);
        self::assertSame('toolu_123', $turn->toolCalls[0]->id);
        $firstBody = json_decode((string) $history[0]['request']->getBody(), true);
        self::assertSame('list_projects', $firstBody['tools'][0]['name']);
        self::assertSame('object', $firstBody['tools'][0]['input_schema']['type']);

        $continuation = new ChatRequest(
            [...$request->messages, $turn->asMessage(), (new ToolResult('toolu_123', 'list_projects', '{"projects":[]}'))->asMessage()],
            tools: $request->tools,
        );
        $final = $provider->turn($continuation);
        self::assertSame('completed', $final->finishReason);
        self::assertSame('Voici vos projets.', $final->text);
        $body = json_decode((string) $history[1]['request']->getBody(), true);
        self::assertSame('tool_use', $body['messages'][1]['content'][1]['type']);
        self::assertSame('tool_result', $body['messages'][2]['content'][0]['type']);
        self::assertSame('toolu_123', $body['messages'][2]['content'][0]['tool_use_id']);
    }

    public function test_openai_tool_continuation_maps_result_and_accepts_final_answer(): void
    {
        $history = [];
        $http = $this->mockHttp([new Response(200, [], json_encode([
            'choices' => [[
                'finish_reason' => 'stop',
                'message' => ['content' => 'Deux projets trouvés.'],
            ]],
        ]))], $history);
        $messages = [
            ['role' => 'user', 'content' => 'Quels projets ?'],
            (new \Leantime\Domain\IdeaRoom\AI\AssistantTurn('', [new ToolCall('call_123', 'list_projects', [])], 'tool_calls'))->asMessage(),
            (new ToolResult('call_123', 'list_projects', '{"projects":[]}'))->asMessage(),
        ];
        $request = new ChatRequest($messages, tools: $this->toolRequest()->tools);
        $turn = (new OpenAiProvider($http, 'private-key', 'test-model'))->turn($request);
        self::assertSame('Deux projets trouvés.', $turn->text);
        $body = json_decode((string) $history[0]['request']->getBody(), true);
        self::assertSame('{}', $body['messages'][3]['tool_calls'][0]['function']['arguments']);
        self::assertSame('call_123', $body['messages'][4]['tool_call_id']);
        self::assertArrayNotHasKey('name', $body['messages'][4]);
    }

    public function test_tool_calls_reject_malformed_arguments_unknown_tools_and_truncation(): void
    {
        foreach ([
            ['finish_reason' => 'tool_calls', 'message' => ['tool_calls' => [[
                'id' => 'a', 'type' => 'function', 'function' => ['name' => 'list_projects', 'arguments' => '[1,2]'],
            ]]]],
            ['finish_reason' => 'tool_calls', 'message' => ['tool_calls' => [[
                'id' => 'a', 'type' => 'function', 'function' => ['name' => 'delete_everything', 'arguments' => '{}'],
            ]]]],
            ['finish_reason' => 'length', 'message' => ['content' => 'partial']],
        ] as $choice) {
            $http = $this->mockHttp([new Response(200, [], json_encode(['choices' => [$choice]]))]);
            try {
                (new OpenAiProvider($http, 'private-key', 'test-model'))->turn($this->toolRequest());
                self::fail('Expected invalid tool response.');
            } catch (ProviderException $e) {
                self::assertStringNotContainsString('delete_everything', $e->getMessage());
            }
        }
    }

    public function test_anthropic_rejects_unknown_tool_and_max_tokens(): void
    {
        foreach ([
            ['stop_reason' => 'tool_use', 'content' => [['type' => 'tool_use', 'id' => 'a', 'name' => 'unsafe', 'input' => []]]],
            ['stop_reason' => 'max_tokens', 'content' => [['type' => 'text', 'text' => 'partial']]],
        ] as $body) {
            $http = $this->mockHttp([new Response(200, [], json_encode($body))]);
            try {
                (new AnthropicProvider($http, 'private-key', 'test-model'))->turn($this->toolRequest());
                self::fail('Expected invalid tool response.');
            } catch (ProviderException $e) {
                self::assertStringNotContainsString('unsafe', $e->getMessage());
            }
        }
    }

    private function toolRequest(): ChatRequest
    {
        return new ChatRequest(
            messages: [['role' => 'user', 'content' => 'Quels projets puis-je voir ?']],
            tools: [new ToolDefinition('list_projects', 'List accessible projects.', [
                'type' => 'object',
                'properties' => ['limit' => ['type' => 'integer']],
            ])],
        );
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
