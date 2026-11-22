<?php

declare(strict_types=1);

namespace App\Enums;

enum ProtectedBrandType: string
{
    /** The brand owns a registrable domain: variants are generated on it. */
    case Registrable = 'registrable';

    /** The brand lives on a subdomain: the full hostname is compared. */
    case Hostname = 'hostname';
}
