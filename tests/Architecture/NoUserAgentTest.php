<?php

use Illuminate\Support\Facades\DB;

it('stores no user-agent column', function () {
    $columns = DB::select(
        "select table_name, column_name from information_schema.columns where table_schema = 'public'"
    );

    $offenders = collect($columns)
        ->map(static fn (object $column): string => $column->table_name.'.'.$column->column_name)
        ->filter(static function (string $column): bool {
            $name = strtolower(explode('.', $column, 2)[1]);

            return $name === 'ua'
                || str_contains($name, 'user_agent')
                || str_contains($name, 'useragent')
                || str_starts_with($name, 'ua_')
                || str_ends_with($name, '_ua');
        })
        ->values()
        ->all();

    expect($offenders)->toBe(
        [],
        'User-agent columns are forbidden in every table, found: '.implode(', ', $offenders)
    );
});
