<?php

declare(strict_types=1);

namespace Leantime\Domain\IdeaRoom\Search;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;

final class SearchProviderFactory
{
    public static function fromEnvironment(?ClientInterface $http = null): ?SearchProvider
    {
        return self::fromConfiguration(
            self::environmentValue('JULIANNA_SEARCH_PROVIDER'),
            self::environmentValue('JULIANNA_GOOGLE_SEARCH_API_KEY'),
            self::environmentValue('JULIANNA_GOOGLE_SEARCH_ENGINE_ID'),
            self::boundedInteger(self::environmentValue('JULIANNA_SEARCH_MAX_RESULTS'), 5, 1, 10),
            self::boundedInteger(self::environmentValue('JULIANNA_SEARCH_TIMEOUT_SECONDS'), 8, 1, 15),
            $http,
        );
    }

    public static function fromConfiguration(
        string $provider,
        string $apiKey,
        string $engineId,
        int $maxResults = 5,
        int $timeoutSeconds = 8,
        ?ClientInterface $http = null,
    ): ?SearchProvider {
        if (strtolower(trim($provider)) !== 'google' || trim($apiKey) === '' || trim($engineId) === '') {
            return null;
        }

        return new GoogleProgrammableSearchProvider(
            $http ?? new Client,
            trim($apiKey),
            trim($engineId),
            max(1, min(10, $maxResults)),
            max(1, min(15, $timeoutSeconds)),
        );
    }

    private static function environmentValue(string $name): string
    {
        $value = $_ENV[$name] ?? getenv($name);

        return is_string($value) ? $value : '';
    }

    private static function boundedInteger(string $value, int $fallback, int $minimum, int $maximum): int
    {
        $parsed = filter_var($value, FILTER_VALIDATE_INT);

        return is_int($parsed) ? max($minimum, min($maximum, $parsed)) : $fallback;
    }
}
