<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;

final class LockReleaseFailedException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The lock release failed.');
    }
}
