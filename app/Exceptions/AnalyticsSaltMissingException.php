<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class AnalyticsSaltMissingException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The analytics salt file is unavailable.');
    }
}
