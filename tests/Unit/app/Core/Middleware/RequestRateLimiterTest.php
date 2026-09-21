<?php

declare(strict_types=1);

namespace Unit\app\Core\Middleware;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\Repository;
use Leantime\Core\Configuration\Environment;
use Leantime\Core\Http\IncomingRequest;
use Leantime\Core\Middleware\RequestRateLimiter;
use Leantime\Domain\JuliannaAuth\Services\RateLimitKeyFactory;
use Symfony\Component\HttpFoundation\Response;
use Unit\TestCase;

final class RequestRateLimiterTest extends TestCase
{
    public function test_registration_allows_only_five_attempts_per_ip_per_hour(): void
    {
        config(['app.debug' => false]);
        session(['isInstalled' => true]);

        $config = (new \ReflectionClass(Environment::class))->newInstanceWithoutConstructor();
        $config->set('ratelimitSignup', 5);
        $config->set('ratelimitGeneral', 1000);
        $config->set('ratelimitApi', 1000);
        $config->set('ratelimitAuth', 20);
        $config->set('ratelimitMcp', 1000);
        $middleware = new RequestRateLimiter(
            $config,
            new RateLimiter(new Repository(new ArrayStore)),
            new RateLimitKeyFactory,
        );
        $next = static fn (): Response => new Response('ok');

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $response = $middleware->handle($this->registrationRequest('203.0.113.10'), $next);
            $this->assertSame(200, $response->getStatusCode());
        }

        $blocked = $middleware->handle($this->registrationRequest('203.0.113.10'), $next);
        $otherIp = $middleware->handle($this->registrationRequest('203.0.113.11'), $next);

        $this->assertSame(429, $blocked->getStatusCode());
        $this->assertSame(200, $otherIp->getStatusCode());
        $retryAfter = (int) $blocked->headers->get('Retry-After');
        $this->assertGreaterThan(3500, $retryAfter);
        $this->assertLessThanOrEqual(3600, $retryAfter);
    }

    private function registrationRequest(string $ip): IncomingRequest
    {
        return IncomingRequest::create(
            '/auth/register',
            'POST',
            ['email' => 'person@example.com'],
            [],
            [],
            ['REMOTE_ADDR' => $ip],
        );
    }
}
