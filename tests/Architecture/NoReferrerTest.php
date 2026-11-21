<?php

use Illuminate\Support\Facades\DB;

it('stores no referer or referrer column', function () {
    $columns = DB::select(
        "select table_name, column_name from information_schema.columns where table_schema = 'public'"
    );

    $offenders = collect($columns)
        ->map(static fn (object $column): string => $column->table_name.'.'.$column->column_name)
        ->filter(static function (string $column): bool {
            $name = strtolower(explode('.', $column, 2)[1]);

            return str_contains($name, 'referer') || str_contains($name, 'referrer');
        })
        ->values()
        ->all();

    expect($offenders)->toBe(
        [],
        'Referer columns are forbidden in every table, found: '.implode(', ', $offenders)
    );
});
