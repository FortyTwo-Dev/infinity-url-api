<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The eight deterministic transformations of a protected brand
 * (docs/04 §3.5, D-131). The value is part of the reason shown to the client.
 */
enum LookalikeVariant: string
{
    case Substitution = 'substitution';

    case Deletion = 'deletion';

    case Insertion = 'insertion';

    case Transposition = 'transposition';

    case Homoglyph = 'homoglyph';

    case Tld = 'tld';

    case Combosquat = 'combosquat';

    case Concatenation = 'concatenation';
}
