<?php

declare(strict_types=1);

namespace Unit\app\Domain\IdeaRoom\AI;

use Illuminate\Http\Request;
use InvalidArgumentException;
use Leantime\Domain\IdeaRoom\AI\TemporaryAiConfiguration;
use Unit\TestCase;

final class TemporaryAiConfigurationTest extends TestCase
{
    private mixed $previousFlag;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousFlag = $_ENV['JULIANNA_AI_TEST_CONNECTOR_ENABLED'] ?? null;
        $_ENV['JULIANNA_AI_TEST_CONNECTOR_ENABLED'] = 'true';
        config(['app.env' => 'testing', 'session.driver' => 'array', 'sessionPassword' => str_repeat('A', 32)]);
        session()->put('userdata', ['id' => 7, 'twoFAVerified' => true]);
        session()->put('julianna_auth.authenticated_account_id', 19);
    }

    protected function tearDown(): void
    {
        session()->forget(['julianna.idea_room.temporary_ai', 'userdata', 'julianna_auth.authenticated_account_id']);
        if ($this->previousFlag === null) {
            unset($_ENV['JULIANNA_AI_TEST_CONNECTOR_ENABLED']);
        } else {
            $_ENV['JULIANNA_AI_TEST_CONNECTOR_ENABLED'] = $this->previousFlag;
        }
        parent::tearDown();
    }

    public function test_key_is_encrypted_and_bound_to_account_and_expiry(): void
    {
        $request = Request::create('http://localhost/idea-room/testing-ai', 'POST');
        $details = TemporaryAiConfiguration::save($request, 'DeepSeek', 'deepseek-flash', 'secret-test-key');
        $stored = session('julianna.idea_room.temporary_ai');

        self::assertSame('deepseek', $details['provider']);
        self::assertGreaterThan(time(), $details['expiresAt']);
        self::assertStringNotContainsString('secret-test-key', json_encode($stored));
        self::assertSame('secret-test-key', TemporaryAiConfiguration::current($request)['apiKey']);
        self::assertArrayNotHasKey('apiKey', TemporaryAiConfiguration::details($request));

        session()->put('julianna_auth.authenticated_account_id', 20);
        self::assertNull(TemporaryAiConfiguration::current($request));
        self::assertNull(session('julianna.idea_room.temporary_ai'));
    }

    public function test_expired_key_is_removed_and_clear_removes_current_key(): void
    {
        $request = Request::create('http://localhost/idea-room/testing-ai', 'POST');
        TemporaryAiConfiguration::save($request, 'kimi', 'kimi-k3', 'another-test-key');
        session()->put('julianna.idea_room.temporary_ai.expiresAt', time() - 1);
        self::assertNull(TemporaryAiConfiguration::current($request));

        TemporaryAiConfiguration::save($request, 'kimi', 'kimi-k3', 'another-test-key');
        TemporaryAiConfiguration::clear($request);
        self::assertNull(TemporaryAiConfiguration::current($request));
    }

    public function test_production_insecure_remote_and_cookie_sessions_are_rejected(): void
    {
        $localhost = Request::create('http://localhost/idea-room/testing-ai', 'POST');
        $remote = Request::create('http://example.test/idea-room/testing-ai', 'POST');

        config(['app.env' => 'production']);
        self::assertFalse(TemporaryAiConfiguration::enabled($localhost));
        config(['app.env' => 'testing']);
        self::assertFalse(TemporaryAiConfiguration::enabled($remote));
        config(['session.driver' => 'cookie']);
        self::assertFalse(TemporaryAiConfiguration::enabled($localhost));
    }

    public function test_invalid_model_and_missing_full_authentication_are_rejected(): void
    {
        $request = Request::create('http://localhost/idea-room/testing-ai', 'POST');
        try {
            TemporaryAiConfiguration::save($request, 'deepseek', 'https://attacker.test/?key=x', 'secret');
            self::fail('Expected model validation to fail.');
        } catch (InvalidArgumentException) {
            self::assertNull(TemporaryAiConfiguration::current($request));
        }

        session()->put('userdata.twoFAVerified', false);
        $this->expectException(InvalidArgumentException::class);
        TemporaryAiConfiguration::save($request, 'deepseek', 'deepseek-flash', 'secret');
    }
}
