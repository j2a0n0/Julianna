<?php

declare(strict_types=1);

namespace Unit\app\Domain\JuliannaAuth\Repositories;

use Illuminate\Database\ConnectionInterface;
use Leantime\Domain\JuliannaAuth\Repositories\SessionRepository;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

final class SessionRepositoryTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function test_touch_extends_only_an_unrevoked_unexpired_session(): void
    {
        $builder = Mockery::mock();
        $builder->shouldReceive('where')->once()->with('session_hash', 'hash')->andReturnSelf();
        $builder->shouldReceive('whereNull')->once()->with('revoked_at')->andReturnSelf();
        $builder->shouldReceive('where')->once()->with('expires_at', '>', '2026-09-17 12:00:00')->andReturnSelf();
        $builder->shouldReceive('update')->once()->with([
            'last_seen_at' => '2026-09-17 12:00:00',
            'expires_at' => '2026-09-17 12:30:00',
        ])->andReturn(1);

        $connection = Mockery::mock(ConnectionInterface::class);
        $connection->shouldReceive('table')->once()->with('julianna_auth_sessions')->andReturn($builder);

        (new SessionRepository($connection))->touch(
            'hash', '2026-09-17 12:00:00', '2026-09-17 12:30:00',
        );
    }
}
