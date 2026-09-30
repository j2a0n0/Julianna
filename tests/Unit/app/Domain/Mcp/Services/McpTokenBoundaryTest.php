<?php

declare(strict_types=1);

namespace Unit\app\Domain\Mcp\Services;

use Illuminate\Auth\AuthManager;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Leantime\Core\Configuration\Environment;
use Leantime\Core\Http\ApiRequest;
use Leantime\Core\Http\IncomingRequest;
use Leantime\Core\Middleware\AuthCheck;
use Leantime\Domain\Api\Services\Api;
use Leantime\Domain\Auth\Services\Auth;
use Symfony\Component\HttpFoundation\Response;
use Unit\TestCase;

final class McpTokenBoundaryTest extends TestCase
{
    public function test_browser_cookie_cannot_authenticate_mcp_transport(): void
    {
        session()->put('userdata', ['id' => 1, 'role' => 'owner']);
        $auth = $this->createMock(AuthFactory::class);
        $auth->expects(self::never())->method('guard');
        $check = new AuthCheck(app(Environment::class), $auth, $this->createMock(AuthManager::class));
        $request = IncomingRequest::create('http://localhost/mcp', 'POST');

        $response = (new \ReflectionMethod(AuthCheck::class, 'authenticateApi'))
            ->invoke($check, $request, ['web']);

        self::assertInstanceOf(Response::class, $response);
        self::assertSame(401, $response->getStatusCode());
        self::assertSame(1, session('userdata.id'));
    }

    public function test_token_auth_keeps_browser_session_middleware_out_of_mcp(): void
    {
        $auth = $this->createMock(AuthFactory::class);
        $auth->expects(self::never())->method('guard');
        $tokenAuth = $this->createMock(Auth::class);
        $tokenAuth->expects(self::once())->method('getUserByToken')->with('opaque-token')
            ->willReturn(['id' => 7, 'username' => 'user@example.test']);
        app()->instance(Auth::class, $tokenAuth);
        $api = $this->createMock(Api::class);
        $api->expects(self::once())->method('setApiUserSession');
        app()->instance(Api::class, $api);
        $request = ApiRequest::create('http://localhost/mcp', 'POST', server: [
            'HTTP_AUTHORIZATION' => 'Bearer opaque-token',
        ]);
        $request->setUserResolver(static fn () => new \stdClass);
        $check = new AuthCheck(app(Environment::class), $auth, $this->createMock(AuthManager::class));

        $response = (new \ReflectionMethod(AuthCheck::class, 'authenticateApi'))
            ->invoke($check, $request, ['web']);

        self::assertTrue($response);
        self::assertNull($request->user());
    }
}
