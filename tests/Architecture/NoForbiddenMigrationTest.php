<?php

it('keeps forbidden tracking columns out of the migration sources', function () {
    $forbidden = ['ip_address', 'user_agent', 'referer', 'referrer'];

    $offenders = [];

    foreach (glob(database_path('migrations/*.php')) as $file) {
        $contents = (string) file_get_contents($file);

        foreach ($forbidden as $needle) {
            if (stripos($contents, $needle) !== false) {
                $offenders[] = basename($file).':'.$needle;
            }
        }
    }

    expect($offenders)->toBe(
        [],
        'Forbidden tracking columns are present in migrations: '.implode(', ', $offenders)
    );
});
