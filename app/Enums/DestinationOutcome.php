<?php

declare(strict_types=1);

namespace App\Enums;

enum DestinationOutcome: string
{
    /** No evidence of danger: the link is created and live. */
    case Accept = 'accept';

    /** A signal, but not a proof: the link is live and an operator reviews it. */
    case Review = 'review';

    /** Proof of danger: the link is refused. */
    case Block = 'block';
}
