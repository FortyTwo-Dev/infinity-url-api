<?php

declare(strict_types=1);

/**
 * Vérifie qu'aucune dépendance n'impose une licence incompatible avec
 * l'AGPL-3.0-only du projet.
 *
 * Une dépendance multi-licenciée est acceptée si **au moins une** de ses
 * licences est compatible : c'est le cas de `nette/schema` et `nette/utils`,
 * proposés en BSD-3-Clause, GPL-2.0-only ou GPL-3.0-only.
 *
 * Usage : php scripts/check-licenses.php
 * Sortie : 0 si tout est compatible, 1 sinon.
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
    fwrite(STDERR, "Impossible de lire la sortie de `composer licenses`.\n");
    exit(2);
}

/** @var array{dependencies?: array<string, array{license?: list<string>}>} $data */
$data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

$incompatible = [];

foreach ($data['dependencies'] ?? [] as $name => $dependency) {
    $licenses = $dependency['license'] ?? [];

    if ($licenses === []) {
        $incompatible[$name] = ['(non déclarée)'];

        continue;
    }

    if (array_intersect($licenses, COMPATIBLE) === []) {
        $incompatible[$name] = $licenses;
    }
}

if ($incompatible === []) {
    echo "Licences : toutes les dépendances sont compatibles avec AGPL-3.0-only.\n";
    exit(0);
}

fwrite(STDERR, "Dépendances incompatibles avec AGPL-3.0-only :\n");

foreach ($incompatible as $name => $licenses) {
    fwrite(STDERR, sprintf("  - %s (%s)\n", $name, implode(', ', $licenses)));
}

exit(1);
