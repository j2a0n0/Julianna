<?php

declare(strict_types=1);

namespace Leantime\Domain\Agent\AI;

/** Best-effort English/French choice for built-in replies; the model sees the full message. */
final class ReplyLocale
{
    public static function forMessage(string $message, string $fallback): string
    {
        $text = mb_strtolower($message);
        $french = self::count($text, '/(*UCP)\b(?:bonjour|salut|merci|peux|pouvez|pourrais|voudrais|aimerais|crée|créer|ajoute|ajouter|fais|faire|comment|pourquoi|avec|dans|pour|une|des|les|vous|nous|mon|ma|mes|notre|projet|tâche|tâches|réponds|répondre)\b/u');
        $english = self::count($text, '/(*UCP)\b(?:hello|hi|thanks|thank|please|could|would|can|create|add|make|how|why|with|from|the|this|that|for|and|you|your|our|my|project|task|tasks|answer|reply)\b/u');
        if (preg_match('/[àâçéèêëîïôùûüÿœæ]/u', $text) === 1) {
            $french++;
        }

        if ($french > $english) {
            return 'fr-CH';
        }
        if ($english > $french) {
            return 'en-US';
        }

        return $fallback;
    }

    private static function count(string $text, string $pattern): int
    {
        return preg_match_all($pattern, $text) ?: 0;
    }
}
