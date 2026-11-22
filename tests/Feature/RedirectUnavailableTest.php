<?php

use App\Models\Domain;
use App\Models\Link;
use Illuminate\Database\LostConnectionException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * @return array<string, mixed>
 */
function deadPostgresConnection(): array
{
    $connection = config('database.connections.pgsql');

    $connection['host'] = '127.0.0.1';
    $connection['port'] = 5433;
    $connection['connect_timeout'] = 1;

    return $connection;
}

it('answers 503 with an empty body when postgres is cold and unavailable', function () {
    $domain = Domain::factory()->forHost('iurl.fr')->create();
    Link::factory()->forDomain($domain)->create([
        'slug' => 'dbdown1',
        'destination' => 'https://example.com/dbdowncold',
    ]);

    Cache::flush();

    $original = DB::getDefaultConnection();
    config(['database.connections.dead' => deadPostgresConnection()]);
    DB::purge('dead');
    DB::setDefaultConnection('dead');

    try {
        $response = $this->get('https://iurl.fr/dbdown1');
    } finally {
        DB::setDefaultConnection($original);
        DB::purge('dead');
    }

    $response->assertStatus(503);
    expect((string) $response->getContent())->toBe('');
});

it('serves a cached redirect when postgres becomes unavailable', function () {
    $domain = Domain::factory()->forHost('iurl.fr')->create();
    Link::factory()->forDomain($domain)->create([
        'slug' => 'dbdown2',
        'destination' => 'https://example.com/dbdownwarm',
    ]);

    $this->get('https://iurl.fr/dbdown2')->assertStatus(302);

    $original = DB::getDefaultConnection();
    config(['database.connections.dead' => deadPostgresConnection()]);
    DB::purge('dead');
    DB::setDefaultConnection('dead');

    try {
        $response = $this->get('https://iurl.fr/dbdown2');
    } finally {
        DB::setDefaultConnection($original);
        DB::purge('dead');
    }

    $response->assertStatus(302);
    $response->assertHeader('Location', 'https://example.com/dbdownwarm');
});

dataset('database failures during resolution', [
    'lost connection' => [
        'dbinject1',
        static fn (): Throwable => new LostConnectionException('lost'),
        503,
    ],
    'query exception with an outage sqlstate' => [
        'dbinject2',
        static fn (): Throwable => new QueryException(
            'pgsql',
            'select 1',
            [],
            new PDOException('SQLSTATE[08006] connection failure'),
        ),
        503,
    ],
    'raw pdo outage' => [
        'dbinject3',
        static fn (): Throwable => new PDOException('SQLSTATE[08006] connection failure'),
        503,
    ],
    'query exception with another cause' => [
        'dbinject4',
        static fn (): Throwable => new QueryException('pgsql', 'select 1', [], new PDOException('boom')),
        500,
    ],
    'raw pdo error' => [
        'dbinject5',
        static fn (): Throwable => new PDOException('boom'),
        500,
    ],
]);

it('maps a database failure during resolution to the right status', function (string $slug, Closure $failure, int $status) {
    $domain = Domain::factory()->forHost('iurl.fr')->create();
    Link::factory()->forDomain($domain)->create([
        'slug' => $slug,
        'destination' => 'https://example.com/dbinject',
    ]);

    Cache::flush();

    DB::connection()->beforeExecuting(function () use ($failure): void {
        throw $failure();
    });

    $this->get('https://iurl.fr/'.$slug)->assertStatus($status);
})->with('database failures during resolution');
