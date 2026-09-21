<?php

declare(strict_types=1);

namespace Leantime\Domain\IdeaRoom\AI;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;

final class ProviderFactory
{
    public static function fromEnvironment(?ClientInterface $http = null): ?AiProvider
    {
        return self::fromConfiguration(
            self::environmentValue('JULIANNA_AI_PROVIDER'),
            self::environmentValue('JULIANNA_AI_API_KEY'),
            self::environmentValue('JULIANNA_AI_MODEL'),
            $http,
        );
    }

    public static function fromConfiguration(
        string $provider,
        string $apiKey,
        string $model,
        ?ClientInterface $http = null,
    ): ?AiProvider {
        $provider = strtolower(trim($provider));
        $apiKey = trim($apiKey);
        $model = trim($model);

        if ($provider === '' || $apiKey === '' || $model === '') {
            return null;
        }

        $http ??= new Client;

        return match ($provider) {
            'openai' => new OpenAiProvider($http, $apiKey, $model),
            'anthropic' => new AnthropicProvider($http, $apiKey, $model),
            default => null,
        };
    }

    private static function environmentValue(string $name): string
    {
        $value = $_ENV[$name] ?? getenv($name);

        return is_string($value) ? $value : '';
    }
}
