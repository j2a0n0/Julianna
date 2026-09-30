<?php

declare(strict_types=1);

namespace Leantime\Domain\Agent\Services;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use RuntimeException;

/** Retrieves key-scoped model IDs from fixed first-party provider endpoints. */
final class ProviderModelCatalog
{
    public function __construct(private readonly ?ClientInterface $http = null) {}

    /** @return list<string> */
    public function list(string $provider, string $apiKey): array
    {
        if ($apiKey === '') {
            throw new RuntimeException('An API key is required.');
        }
        [$url, $headers] = match ($provider) {
            'openai' => ['https://api.openai.com/v1/models', ['Authorization' => 'Bearer '.$apiKey]],
            'anthropic' => ['https://api.anthropic.com/v1/models?limit=1000', [
                'x-api-key' => $apiKey,
                'anthropic-version' => '2023-06-01',
            ]],
            'deepseek' => ['https://api.deepseek.com/models', ['Authorization' => 'Bearer '.$apiKey]],
            'kimi' => ['https://api.moonshot.ai/v1/models', ['Authorization' => 'Bearer '.$apiKey]],
            default => throw new RuntimeException('Unsupported provider.'),
        };

        $ids = [];
        for ($page = 0; $page < 10; $page++) {
            $response = ($this->http ?? new Client)->request('GET', $url, [
                'headers' => $headers + ['Accept' => 'application/json'],
                'connect_timeout' => 5,
                'timeout' => 15,
                'allow_redirects' => false,
                'http_errors' => false,
            ]);
            if ($response->getStatusCode() !== 200) {
                throw new RuntimeException('Model listing failed.');
            }

            $body = (string) $response->getBody();
            if (strlen($body) > 2000000) {
                throw new RuntimeException('Model listing is too large.');
            }
            $data = json_decode($body, true);
            if (! is_array($data) || ! is_array($data['data'] ?? null)) {
                throw new RuntimeException('Invalid model listing.');
            }
            foreach ($data['data'] as $entry) {
                $id = is_array($entry) ? ($entry['id'] ?? null) : null;
                if (is_string($id) && preg_match('/\A[A-Za-z0-9._:\/-]{1,128}\z/D', $id) === 1) {
                    $ids[$id] = true;
                }
            }

            if ($provider !== 'anthropic' || ($data['has_more'] ?? false) !== true) {
                $models = array_keys($ids);
                natcasesort($models);

                return array_values($models);
            }
            $cursor = $data['last_id'] ?? null;
            if (! is_string($cursor) || preg_match('/\A[A-Za-z0-9._:\/-]{1,128}\z/D', $cursor) !== 1) {
                throw new RuntimeException('Invalid model listing cursor.');
            }
            $url = 'https://api.anthropic.com/v1/models?limit=1000&after_id='.rawurlencode($cursor);
        }

        throw new RuntimeException('Model listing has too many pages.');
    }
}
