<?php

use App\Models\Domain;
use App\Models\Link;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Concurrency;

it('performs a single links read for one hundred concurrent cold requests', function () {
    $domain = Domain::factory()->forHost('iurl.fr')->create();
    Link::factory()->forDomain($domain)->create([
        'slug' => 'singleflight1',
        'destination' => 'https://example.com/singleflight',
    ]);

    $slug = 'singleflight1';
    $task = function () use ($slug) {
        // The concurrency driver boots a fresh application in each process.
        $app = app();
        $kernel = $app->make(Kernel::class);
        $kernel->bootstrap();

        $reads = 0;

        // Hold the first links lookup open long enough for the other processes
        // to reach the cache and wait on the application lock.
        $app->make('db')->connection()->beforeExecuting(function (string $query): void {
            if (str_contains($query, 'from "links"')) {
                usleep(500_000);
            }
        });

        $app->make('db')->listen(function ($query) use (&$reads): void {
            if (str_contains($query->sql, 'from "links"')) {
                $reads++;
            }
        });

        $request = Request::create("https://iurl.fr/{$slug}");
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);

        return [
            'status' => $response->getStatusCode(),
            'reads' => $reads,
        ];
    };

    // Unbind the closure from Pest's generated test class so the child process
    // does not try to autoload a class that only exists in the parent suite.
    $tasks = array_fill(0, 100, Closure::bind($task, null, null));

    $results = Concurrency::run($tasks);

    $statuses = array_column($results, 'status');
    $reads = array_sum(array_column($results, 'reads'));

    expect($statuses)->toHaveCount(100);
    expect(array_filter($statuses, static fn (int $status): bool => $status !== 302))->toBe([]);
    expect($reads)->toBe(1);
});
