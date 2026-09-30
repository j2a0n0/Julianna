<?php

declare(strict_types=1);

namespace Unit\app\Domain\Agent;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use Leantime\Domain\Agent\Services\ProviderModelCatalog;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ProviderModelCatalogTest extends TestCase
{
    public function test_each_provider_uses_its_own_fixed_model_endpoint_and_key_header(): void
    {
        foreach ([
            'openai' => ['https://api.openai.com/v1/models', 'Authorization'],
            'anthropic' => ['https://api.anthropic.com/v1/models?limit=1000', 'x-api-key'],
            'deepseek' => ['https://api.deepseek.com/models', 'Authorization'],
            'kimi' => ['https://api.moonshot.ai/v1/models', 'Authorization'],
        ] as $provider => [$endpoint, $keyHeader]) {
            $http = $this->createMock(ClientInterface::class);
            $http->expects(self::once())->method('request')->willReturnCallback(
                static function (string $method, string $url, array $options) use ($endpoint, $keyHeader): Response {
                    self::assertSame('GET', $method);
                    self::assertSame($endpoint, $url);
                    self::assertStringContainsString('test-key', $options['headers'][$keyHeader]);
                    self::assertFalse($options['allow_redirects']);

                    return new Response(200, [], '{"data":[{"id":"model-z"},{"id":"model-a"},{"id":"model-z"},{"id":"invalid id"}]}');
                },
            );

            self::assertSame(['model-a', 'model-z'], (new ProviderModelCatalog($http))->list($provider, 'test-key'));
        }
    }

    public function test_anthropic_pagination_collects_all_models(): void
    {
        $http = $this->createMock(ClientInterface::class);
        $http->expects(self::exactly(2))->method('request')->willReturnOnConsecutiveCalls(
            new Response(200, [], '{"data":[{"id":"model-b"}],"has_more":true,"last_id":"model-b"}'),
            new Response(200, [], '{"data":[{"id":"model-a"}],"has_more":false}'),
        );

        self::assertSame(['model-a', 'model-b'], (new ProviderModelCatalog($http))->list('anthropic', 'test-key'));
    }

    public function test_rejects_provider_errors_without_exposing_the_key(): void
    {
        $http = $this->createMock(ClientInterface::class);
        $http->method('request')->willReturn(new Response(401, [], 'secret response'));

        try {
            (new ProviderModelCatalog($http))->list('openai', 'test-key');
            self::fail('A provider error must fail the model lookup.');
        } catch (RuntimeException $exception) {
            self::assertStringNotContainsString('test-key', $exception->getMessage());
            self::assertStringNotContainsString('secret response', $exception->getMessage());
        }
    }
}
