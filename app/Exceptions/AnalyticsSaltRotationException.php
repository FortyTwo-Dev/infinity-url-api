<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class AnalyticsSaltRotationException extends RuntimeException
{
    public function __construct(string $reason)
    {
        parent::__construct('Unable to rotate the analytics salt: '.$reason);
    }
}
