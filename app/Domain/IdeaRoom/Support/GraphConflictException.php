<?php

declare(strict_types=1);

namespace Leantime\Domain\IdeaRoom\Support;

use Leantime\Core\Exceptions\LeantimeException;

final class GraphConflictException extends LeantimeException
{
    protected int $statusCode = 409;

    protected int $rpcCode = -32005;

    public function __construct()
    {
        parent::__construct('The canvas changed. Reload it and try again.');
    }
}
