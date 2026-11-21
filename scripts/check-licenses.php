<?php

declare(strict_types=1);

/**
 * Fails when a dependency forces a licence that AGPL-3.0-only cannot absorb.
 *
 * A multi-licensed dependency is accepted when at least one of its licences is
 * compatible: `nette/schema` and `nette/utils` ship as BSD-3-Clause,
 * GPL-2.0-only or GPL-3.0-only, and picking BSD is fine.
 *
 * Usage: php scripts/check-licenses.php
 * Exit: 0 when every dependency is compatible, 1 otherwise.
 */
const COMPATIBLE = [
    'MIT',
    'ISC',
    '0BSD',
    'BSD-2-Clause',
    'BSD-3-Clause',
    'Apache-2.0',
    'Unlicense',
    'CC0-1.0',
    'LGPL-2.1-only',
    'LGPL-2.1-or-later',
    'LGPL-3.0-only',
    'LGPL-3.0-or-later',
    'GPL-3.0-only',
    'GPL-3.0-or-later',
    'AGPL-3.0-only',
    'AGPL-3.0-or-later',
];

$json = shell_exec('composer licenses --format=json 2>/dev/null');

if (! is_string($json) || $json === '') {
    fwrite(STDERR, "Cannot read the output of `composer licenses`.\n");
    exit(2);
}

/** @var array{dependencies?: array<string, array{license?: list<string>}>} $data */
$data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

$incompatible = [];

foreach ($data['dependencies'] ?? [] as $name => $dependency) {
    $licenses = $dependency['license'] ?? [];

    if ($licenses === []) {
        $incompatible[$name] = ['(undeclared)'];

        continue;
    }

    if (array_intersect($licenses, COMPATIBLE) === []) {
        $incompatible[$name] = $licenses;
    }
}

if ($incompatible === []) {
    echo "Licences: every dependency is compatible with AGPL-3.0-only.\n";
    exit(0);
}

fwrite(STDERR, "Dependencies incompatible with AGPL-3.0-only:\n");

foreach ($incompatible as $name => $licenses) {
    fwrite(STDERR, sprintf("  - %s (%s)\n", $name, implode(', ', $licenses)));
}

exit(1);
