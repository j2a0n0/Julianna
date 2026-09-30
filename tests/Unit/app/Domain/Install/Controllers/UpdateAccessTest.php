<?php

declare(strict_types=1);

namespace Unit\app\Domain\Install\Controllers;

use Leantime\Domain\Install\Controllers\Update;
use ReflectionClass;
use Symfony\Component\HttpFoundation\Response;

class UpdateAccessTest extends \Unit\TestCase
{
    protected function tearDown(): void
    {
        unset($_POST['_token'], $_POST['updateDB']);
        session()->forget(['julianna_auth.authenticated_account_id', 'userdata.role']);
        parent::tearDown();
    }

    private function controller(): Update
    {
        // Denied requests must be rejected before the controller can touch the
        // migration service or render an update form.
        return (new ReflectionClass(Update::class))->newInstanceWithoutConstructor();
    }

    public function test_anonymous_user_cannot_view_or_start_an_update(): void
    {
        session()->forget(['julianna_auth.authenticated_account_id', 'userdata.role']);
        $_POST['updateDB'] = '1';

        $this->assertSame(Response::HTTP_FORBIDDEN, $this->controller()->get([])->getStatusCode());
        $this->assertSame(Response::HTTP_FORBIDDEN, $this->controller()->post([])->getStatusCode());
    }

    public function test_non_owner_cannot_start_an_update(): void
    {
        session(['julianna_auth.authenticated_account_id' => 7, 'userdata.role' => 'admin']);
        $_POST['updateDB'] = '1';

        $this->assertSame(Response::HTTP_FORBIDDEN, $this->controller()->post([])->getStatusCode());
    }

    public function test_owner_request_without_csrf_cannot_start_an_update(): void
    {
        session(['julianna_auth.authenticated_account_id' => 7, 'userdata.role' => 'owner']);
        $_POST['updateDB'] = '1';

        $this->assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->controller()->post([])->getStatusCode());
    }
}
