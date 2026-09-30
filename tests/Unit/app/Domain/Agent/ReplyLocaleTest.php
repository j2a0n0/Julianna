<?php

declare(strict_types=1);

namespace Unit\app\Domain\Agent;

use Leantime\Domain\Agent\AI\ReplyLocale;
use PHPUnit\Framework\TestCase;

final class ReplyLocaleTest extends TestCase
{
    public function test_detects_supported_message_language_and_keeps_fallback_for_ambiguity(): void
    {
        self::assertSame('en-US', ReplyLocale::forMessage('Could you create a task for my project?', 'fr-CH'));
        self::assertSame('fr-CH', ReplyLocale::forMessage('Peux-tu créer une tâche pour mon projet ?', 'en-US'));
        self::assertSame('fr-CH', ReplyLocale::forMessage('OK', 'fr-CH'));
    }
}
