<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * A click job must run through a queue, which supplies the UUID the
 * idempotence guard relies on.
 */
final class AnalyticsJobMisconfiguredException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The analytics job ran without a queue job wrapper.');
    }
}
