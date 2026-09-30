<?php

declare(strict_types=1);

namespace Leantime\Domain\Mcp\Services;

use InvalidArgumentException;

/** Small strict subset of JSON Schema used by the first-party tool catalog. */
final class SchemaValidator
{
    /** @param array<string, mixed> $schema */
    public function validate(mixed $value, array $schema): void
    {
        $encoded = json_encode($value);
        // Base64 encoding expands the Whiteboard service's 32 MiB decoded
        // asset budget by about one third; leave room for the 4 MiB scene.
        if ($encoded === false || strlen($encoded) > 52 * 1024 * 1024) {
            throw new InvalidArgumentException('Tool input is too large or invalid.');
        }
        $this->validateValue($value, $schema, 'arguments', 0);
    }

    /** @param array<string, mixed> $schema */
    private function validateValue(mixed $value, array $schema, string $path, int $depth): void
    {
        if ($depth > 12) {
            throw new InvalidArgumentException('Tool input is too deeply nested.');
        }
        $type = $schema['type'] ?? null;
        $valid = match ($type) {
            'object' => is_array($value) && (! array_is_list($value) || $value === []),
            'array' => is_array($value) && array_is_list($value),
            'string' => is_string($value),
            'integer' => is_int($value),
            'number' => is_int($value) || is_float($value),
            'boolean' => is_bool($value),
            default => false,
        };
        if (! $valid) {
            throw new InvalidArgumentException("Invalid {$path}.");
        }
        if ($type === 'object') {
            $properties = (array) ($schema['properties'] ?? []);
            foreach ($schema['required'] ?? [] as $key) {
                if (! array_key_exists($key, $value)) {
                    throw new InvalidArgumentException("Missing {$path}.{$key}.");
                }
            }
            foreach ($value as $key => $item) {
                if (! is_string($key)) {
                    throw new InvalidArgumentException("Invalid {$path} key.");
                }
                if (isset($properties[$key])) {
                    $this->validateValue($item, $properties[$key], "{$path}.{$key}", $depth + 1);
                } elseif (($schema['additionalProperties'] ?? true) === false) {
                    throw new InvalidArgumentException("Unknown {$path} field.");
                } else {
                    $this->validateOpenValue($item, $depth + 1);
                }
            }
        } elseif ($type === 'array') {
            if (count($value) < ($schema['minItems'] ?? 0) || count($value) > ($schema['maxItems'] ?? PHP_INT_MAX)) {
                throw new InvalidArgumentException("Invalid {$path} length.");
            }
            foreach ($value as $index => $item) {
                $this->validateValue($item, $schema['items'], "{$path}[{$index}]", $depth + 1);
            }
        } elseif ($type === 'string') {
            if (mb_strlen($value) < ($schema['minLength'] ?? 0) || mb_strlen($value) > ($schema['maxLength'] ?? PHP_INT_MAX)) {
                throw new InvalidArgumentException("Invalid {$path} length.");
            }
            if (isset($schema['pattern']) && preg_match('/'.$schema['pattern'].'/D', $value) !== 1) {
                throw new InvalidArgumentException("Invalid {$path} format.");
            }
        } elseif ($type === 'integer' || $type === 'number') {
            if ($value < ($schema['minimum'] ?? -INF) || $value > ($schema['maximum'] ?? INF)) {
                throw new InvalidArgumentException("Invalid {$path} range.");
            }
        }
        if (isset($schema['enum']) && ! in_array($value, $schema['enum'], true)) {
            throw new InvalidArgumentException("Invalid {$path} value.");
        }
    }

    private function validateOpenValue(mixed $value, int $depth): void
    {
        if ($depth > 12) {
            throw new InvalidArgumentException('Tool input is too deeply nested.');
        }
        if (! is_array($value)) {
            if (! is_scalar($value) && $value !== null) {
                throw new InvalidArgumentException('Invalid tool input value.');
            }
            return;
        }
        if (count($value) > 10000) {
            throw new InvalidArgumentException('Tool input has too many fields.');
        }
        foreach ($value as $item) {
            $this->validateOpenValue($item, $depth + 1);
        }
    }
}
