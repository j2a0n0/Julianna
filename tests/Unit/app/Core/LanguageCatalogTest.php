<?php

declare(strict_types=1);

namespace Unit\app\Core;

use Leantime\Core\Language;
use Unit\TestCase;

class LanguageCatalogTest extends TestCase
{
    private const ENGLISH_EQUIVALENT_ALLOWLIST = [
        'headlines.logo',
        'headlines.plugins',
        'label.id',
        'label.version',
        'language.code',
        'language.direction',
        'language.isMeridian',
        'language.isRTL',
        'links.api',
        'links.discord',
        'links.documentation',
    ];

    public function test_only_english_and_swiss_french_are_supported(): void
    {
        $this->assertSame(['en-US', 'fr-CH'], Language::SUPPORTED_LANGUAGES);
    }

    public function test_swiss_french_catalog_is_complete_and_structurally_safe(): void
    {
        $english = $this->parseCatalog('en-US.ini');
        $french = $this->parseCatalog('fr-CH.ini');

        $this->assertSame([], array_values(array_diff(array_keys($english), array_keys($french))), 'Swiss French is missing English keys.');
        $this->assertSame([], array_values(array_diff(array_keys($french), array_keys($english))), 'Swiss French contains keys absent from English.');

        $errors = [];
        foreach ($english as $key => $englishValue) {
            $frenchValue = $french[$key];

            if ($englishValue !== '' && trim($frenchValue) === '') {
                $errors[] = "$key: translation is empty";
            }

            $this->compareTokens($errors, $key, 'printf placeholders', $englishValue, $frenchValue, '/%(?:\d+\$)?[bcdeEfFgGosuxX%]/');
            $this->compareTokens($errors, $key, 'interpolation tokens', $englishValue, $frenchValue, '/\{\{[^}]+\}\}|\{[^}]+\}/');
            $this->compareTokens($errors, $key, 'HTML tags', $englishValue, $frenchValue, '/<\/?[a-z]+/i');
            $this->compareTokens($errors, $key, 'URLs', $englishValue, $frenchValue, '~https?://[^\s\'"<>]+~i');

            if (
                $englishValue === $frenchValue
                && ! in_array($key, self::ENGLISH_EQUIVALENT_ALLOWLIST, true)
                && $this->looksLikeEnglishCopy($englishValue)
            ) {
                $errors[] = "$key: value still matches meaningful English copy";
            }
        }

        $this->assertSame([], $errors, implode("\n", $errors));
    }

    /** @return array<string, string> */
    private function parseCatalog(string $filename): array
    {
        $path = dirname(__DIR__, 4).'/app/Language/'.$filename;
        $catalog = parse_ini_file($path, false, INI_SCANNER_RAW);

        $this->assertIsArray($catalog, "Unable to parse $filename.");

        return $catalog;
    }

    /** @param list<string> $errors */
    private function compareTokens(array &$errors, string $key, string $label, string $english, string $french, string $pattern): void
    {
        preg_match_all($pattern, $english, $englishMatches);
        preg_match_all($pattern, $french, $frenchMatches);
        sort($englishMatches[0]);
        sort($frenchMatches[0]);

        if ($englishMatches[0] !== $frenchMatches[0]) {
            $errors[] = "$key: $label differ";
        }
    }

    private function looksLikeEnglishCopy(string $value): bool
    {
        $plainText = trim(html_entity_decode(strip_tags($value)));

        return strlen($plainText) >= 8 && preg_match(
            '/\b(?:the|and|or|to|of|for|from|with|your|you|my|is|are|create|edit|delete|save|add|remove|update|view|show|new|all|project|task|todo|ticket|goal|user|settings|status|priority|report|comment|file|date|time|success|error|start|stop|next|back|search|select|welcome|login)\b/i',
            $plainText,
        ) === 1;
    }
}
