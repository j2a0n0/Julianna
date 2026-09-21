<?php

declare(strict_types=1);

namespace Unit\app\Domain\JuliannaAuth\Repositories;

use Illuminate\Database\ConnectionInterface;
use Leantime\Domain\JuliannaAuth\Enums\TokenPurpose;
use Leantime\Domain\JuliannaAuth\Repositories\AuthTokenRepository;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

final class AuthTokenRepositoryTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function test_usable_lookup_requires_purpose_expiry_and_non_consumption(): void
    {
        $builder = Mockery::mock();
        $builder->shouldReceive('where')->once()->with('token_hash', 'hash')->andReturnSelf();
        $builder->shouldReceive('where')->once()->with('purpose', TokenPurpose::PASSWORD_RESET->value)->andReturnSelf();
        $builder->shouldReceive('whereNull')->once()->with('consumed_at')->andReturnSelf();
        $builder->shouldReceive('where')->once()->with('expires_at', '>', '2026-09-17 12:00:00')->andReturnSelf();
        $builder->shouldReceive('lockForUpdate')->once()->andReturnSelf();
        $builder->shouldReceive('first')->once()->andReturn((object) ['id' => 4, 'account_id' => 9]);

        $connection = Mockery::mock(ConnectionInterface::class);
        $connection->shouldReceive('table')->once()->with('julianna_auth_tokens')->andReturn($builder);

        $token = (new AuthTokenRepository($connection))->lockUsable(
            'hash',
            TokenPurpose::PASSWORD_RESET,
            '2026-09-17 12:00:00',
        );

        $this->assertSame(4, $token['id']);
        $this->assertSame(9, $token['account_id']);
    }

    public function test_consumption_is_single_use(): void
    {
        $first = Mockery::mock();
        $first->shouldReceive('where')->with('id', 4)->andReturnSelf();
        $first->shouldReceive('whereNull')->with('consumed_at')->andReturnSelf();
        $first->shouldReceive('update')->with(['consumed_at' => 'now'])->andReturn(1);

        $second = Mockery::mock();
        $second->shouldReceive('where')->with('id', 4)->andReturnSelf();
        $second->shouldReceive('whereNull')->with('consumed_at')->andReturnSelf();
        $second->shouldReceive('update')->with(['consumed_at' => 'later'])->andReturn(0);

        $connection = Mockery::mock(ConnectionInterface::class);
        $connection->shouldReceive('table')->twice()->with('julianna_auth_tokens')->andReturn($first, $second);
        $repository = new AuthTokenRepository($connection);

        $this->assertTrue($repository->consume(4, 'now'));
        $this->assertFalse($repository->consume(4, 'later'));
    }

    public function test_latest_verification_issue_time_is_scoped_to_account_and_purpose(): void
    {
        $builder = Mockery::mock();
        $builder->shouldReceive('where')->once()->with('account_id', 7)->andReturnSelf();
        $builder->shouldReceive('where')->once()->with('purpose', TokenPurpose::EMAIL_VERIFICATION->value)->andReturnSelf();
        $builder->shouldReceive('orderByDesc')->once()->with('created_at')->andReturnSelf();
        $builder->shouldReceive('value')->once()->with('created_at')->andReturn('2026-09-17 12:00:00');

        $connection = Mockery::mock(ConnectionInterface::class);
        $connection->shouldReceive('table')->once()->with('julianna_auth_tokens')->andReturn($builder);

        $this->assertSame(
            '2026-09-17 12:00:00',
            (new AuthTokenRepository($connection))->latestCreatedAt(7, TokenPurpose::EMAIL_VERIFICATION),
        );
    }
}
