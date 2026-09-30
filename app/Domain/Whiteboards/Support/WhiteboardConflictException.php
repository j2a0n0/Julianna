<?php

declare(strict_types=1);

namespace Leantime\Domain\Whiteboards\Support;

use RuntimeException;

final class WhiteboardConflictException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This whiteboard changed since you opened it. Reload before saving or restoring.');
    }
}
