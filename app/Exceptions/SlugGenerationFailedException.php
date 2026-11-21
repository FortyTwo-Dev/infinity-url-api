<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class SlugGenerationFailedException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Unable to generate a free slug.');
    }
}
