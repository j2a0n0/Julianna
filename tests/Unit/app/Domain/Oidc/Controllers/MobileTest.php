<?php

namespace Tests\Unit\app\Domain\Oidc\Controllers;

use Leantime\Core\Http\IncomingRequest;
use Leantime\Core\Language;
use Leantime\Core\UI\Template;
use Leantime\Domain\Oidc\Controllers\Mobile;

/** Ensures the removed upstream mobile OIDC bridge always fails closed. */
class MobileTest extends \Unit\TestCase
{
    public function test_exchange_is_unavailable_for_every_http_method(): void
    {
        foreach (['GET', 'POST'] as $method) {
            $request = IncomingRequest::create(
                '/oidc/mobile/exchange',
                $method,
                ['code' => 'ignored', 'code_verifier' => 'ignored'],
            );
            $controller = new Mobile(
                $request,
                $this->createMock(Template::class),
                $this->createMock(Language::class),
            );

            $response = $controller->exchange([]);
            $this->assertSame(404, $response->getStatusCode());
            $this->assertSame(['error' => 'not_found'], json_decode($response->getContent(), true));
        }
    }
}
