<?php

use Illuminate\Support\Facades\DB;

/**
 * @return array<int, string>
 */
function publicSchemaTableNames(): array
{
    $rows = DB::select(
        "select table_name from information_schema.tables where table_schema = 'public' and table_type = 'BASE TABLE'"
    );

    return array_map(static fn (object $row): string => $row->table_name, $rows);
}

/**
 * @return array<int, string>
 */
function publicSchemaColumns(): array
{
    $rows = DB::select(
        "select table_name, column_name from information_schema.columns where table_schema = 'public'"
    );

    return array_map(
        static fn (object $row): string => $row->table_name.'.'.$row->column_name,
        $rows
    );
}

it('stores no column whose name starts with ip', function () {
    $columns = collect(publicSchemaColumns())
        ->filter(static function (string $column): bool {
            $name = strtolower(str_contains($column, '.') ? explode('.', $column)[1] : $column);

            return str_starts_with($name, 'ip')
                || str_ends_with($name, '_ip')
                || str_contains($name, '_ip_');
        })
        ->values()
        ->all();

    expect($columns)->toBe(
        [],
        'IP-address columns are forbidden in every table, found: '.implode(', ', $columns)
    );
});

it('stores no click event table', function () {
    $tables = collect(publicSchemaTableNames())
        ->filter(static function (string $table): bool {
            $name = strtolower($table);

            return str_contains($name, 'click')
                || str_contains($name, 'visit')
                || str_contains($name, 'event');
        })
        ->values()
        ->all();

    expect($tables)->toBe(
        [],
        'Click-level event tables are forbidden, found: '.implode(', ', $tables)
    );
});
