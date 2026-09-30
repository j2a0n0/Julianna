<?php

declare(strict_types=1);

namespace Leantime\Domain\IdeaRoom\AI;

use Psr\Http\Message\StreamInterface;

/** Bounded parser for provider server-sent events. */
final class SseFrames
{
    /** @param callable(string, string): void $onFrame */
    public static function consume(StreamInterface $stream, callable $onFrame): void
    {
        $buffer = '';
        $bytes = 0;
        while (! $stream->eof()) {
            $chunk = $stream->read(8192);
            if ($chunk === '') {
                throw new ProviderException('AI provider returned an incomplete response.');
            }
            $bytes += strlen($chunk);
            if ($bytes > 1000000) {
                throw new ProviderException('AI provider returned an invalid response.');
            }
            $buffer .= $chunk;
            while (preg_match('/\r?\n\r?\n/', $buffer, $match, PREG_OFFSET_CAPTURE) === 1) {
                $offset = $match[0][1];
                $boundary = $match[0][0];
                $frame = substr($buffer, 0, $offset);
                $buffer = substr($buffer, $offset + strlen($boundary));
                self::frame($frame, $onFrame);
            }
        }
        if (trim($buffer) !== '') {
            throw new ProviderException('AI provider returned an incomplete response.');
        }
    }

    /** @param callable(string, string): void $onFrame */
    private static function frame(string $frame, callable $onFrame): void
    {
        $event = 'message';
        $data = [];
        foreach (preg_split('/\r?\n/', $frame) ?: [] as $line) {
            if (str_starts_with($line, 'event:')) {
                $event = trim(substr($line, 6));
            } elseif (str_starts_with($line, 'data:')) {
                $data[] = ltrim(substr($line, 5));
            }
        }
        if ($data !== []) {
            $onFrame($event, implode("\n", $data));
        }
    }
}
