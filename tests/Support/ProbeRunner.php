<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Process\InvokedProcessPool;
use Illuminate\Process\Pool;
use Illuminate\Support\Facades\Process;

/**
 * Run HTTP requests in parallel PHP processes, each booting the application and
 * dispatching a real kernel request against the test database and Valkey.
 *
 * Every probe bootstraps the application, announces readiness, then waits for
 * one common release file so the requests are dispatched inside the same
 * window. The barrier directory is pinned through the child environment.
 */
final class ProbeRunner
{
    private const READY_PREFIX = 'ready-';

    private const RELEASE = 'release';

    private const TIMEOUT_SECONDS = 30;

    private const POLL_MICROSECONDS = 2_000;

    private const PROBE_TIMEOUT_SECONDS = 60;

    /**
     * Why the guard exists: a spawned probe boots the application in its own
     * process. If `config:cache` is in place, the child would read the cached
     * configuration (possibly the development database) and ignore the
     * environment passed to it.
     */
    public static function unavailableReason(): ?string
    {
        if (! function_exists('proc_open')) {
            return 'proc_open is disabled, so parallel PHP processes cannot be started.';
        }

        if (file_exists(app()->getCachedConfigPath())) {
            return 'The configuration is cached; a spawned probe could boot against a stale environment.';
        }

        return null;
    }

    /**
     * @param  list<array{method?: string, url: string, body?: array<string, mixed>, cookies?: array<string, string>, headers?: array<string, string>}>  $requests
     * @return list<array{status: int, body: mixed, cookie: ?string}>
     */
    public static function run(array $requests): array
    {
        $barrier = self::createBarrier();

        try {
            $invoked = self::start($requests, $barrier);

            self::waitUntilReady($invoked, $barrier, count($requests));

            touch(self::path($barrier, self::RELEASE));

            return self::results($invoked);
        } finally {
            self::cleanUp($barrier);
        }
    }

    private static function createBarrier(): string
    {
        $barrier = sys_get_temp_dir().'/iurl_barrier_'.bin2hex(random_bytes(8));

        if (! mkdir($barrier, 0700)) {
            throw new ProbeException('Could not create the probe barrier directory: '.$barrier);
        }

        return $barrier;
    }

    /**
     * @param  list<array{method?: string, url: string, body?: array<string, mixed>, cookies?: array<string, string>, headers?: array<string, string>}>  $requests
     */
    private static function start(array $requests, string $barrier): InvokedProcessPool
    {
        $script = self::writeProbeScript($barrier);
        $environment = self::environment($barrier);

        $commands = [];

        foreach ($requests as $index => $request) {
            $commands[] = [
                PHP_BINARY,
                $script,
                (string) $index,
                base_path(),
                $request['method'] ?? 'POST',
                $request['url'],
                json_encode($request['body'] ?? []),
                json_encode($request['cookies'] ?? []),
                json_encode($request['headers'] ?? []),
            ];
        }

        return Process::pool(function (Pool $pool) use ($commands, $environment): void {
            foreach ($commands as $command) {
                $pool->path(base_path())
                    ->timeout(self::PROBE_TIMEOUT_SECONDS)
                    ->env($environment)
                    ->command($command);
            }
        })->start();
    }

    private static function writeProbeScript(string $barrier): string
    {
        $script = self::path($barrier, 'probe.php');

        file_put_contents($script, self::probeScript());

        return $script;
    }

    private static function probeScript(): string
    {
        return <<<'PHP'
<?php

$id = $argv[1];
$base = $argv[2];
$method = $argv[3];
$url = $argv[4];
$body = $argv[5];
$cookies = $argv[6];
$headers = $argv[7];

$barrier = (string) getenv('IURL_PROBE_BARRIER');
$release = (string) getenv('IURL_PROBE_RELEASE');
$readyPrefix = (string) getenv('IURL_PROBE_READY_PREFIX');

require $base.'/vendor/autoload.php';

$app = require $base.'/bootstrap/app.php';

$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);

$kernel->bootstrap();

$request = Illuminate\Http\Request::create(
    $url,
    $method,
    [],
    json_decode($cookies, true) ?: [],
    [],
    [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
    ],
    $body,
);

foreach (json_decode($headers, true) ?: [] as $name => $value) {
    $request->headers->set($name, $value);
}

// The application is booted: announce it, then wait for the common release so
// every probe dispatches inside the same window.
touch($barrier.'/'.$readyPrefix.$id);

$deadline = microtime(true) + 30;

while (! is_file($barrier.'/'.$release)) {
    if (microtime(true) > $deadline) {
        fwrite(STDERR, 'probe '.$id.' timed out waiting for the release');

        exit(1);
    }

    usleep(2000);
}

$response = $kernel->handle($request);
$kernel->terminate($request, $response);

$refresh = null;

foreach ($response->headers->getCookies() as $cookie) {
    if ($cookie->getName() === '__Host-iurl_refresh') {
        $refresh = $cookie->getValue();
    }
}

echo json_encode([
    'status' => $response->getStatusCode(),
    'body' => json_decode((string) $response->getContent(), true),
    'cookie' => $refresh,
]);
PHP;
    }

    /**
     * @return array<string, string>
     */
    private static function environment(string $barrier): array
    {
        $connection = config('database.connections.'.config('database.default'));
        $redis = config('database.redis.default');

        return [
            'APP_ENV' => 'testing',
            'APP_KEY' => (string) config('app.key'),
            'BLIND_INDEX_KEY' => (string) config('identity.blind_index_key'),
            'ANALYTICS_SALT_PATH' => (string) config('analytics.salt_path'),
            'DB_CONNECTION' => (string) config('database.default'),
            'DB_DATABASE' => (string) $connection['database'],
            'DB_HOST' => (string) $connection['host'],
            'DB_PORT' => (string) $connection['port'],
            'DB_USERNAME' => (string) $connection['username'],
            'DB_PASSWORD' => (string) $connection['password'],
            'CACHE_STORE' => (string) config('cache.default'),
            'REDIS_CLIENT' => (string) config('database.redis.client'),
            'REDIS_HOST' => (string) $redis['host'],
            'REDIS_PORT' => (string) $redis['port'],
            'REDIS_DB' => (string) $redis['database'],
            'REDIS_CACHE_DB' => (string) config('database.redis.cache.database'),
            'MAIL_MAILER' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'SESSION_DRIVER' => 'array',
            'IURL_PROBE_BARRIER' => $barrier,
            'IURL_PROBE_RELEASE' => self::RELEASE,
            'IURL_PROBE_READY_PREFIX' => self::READY_PREFIX,
        ];
    }

    private static function waitUntilReady(InvokedProcessPool $invoked, string $barrier, int $count): void
    {
        $deadline = microtime(true) + self::TIMEOUT_SECONDS;

        foreach (range(0, $count - 1) as $index) {
            $ready = self::path($barrier, self::READY_PREFIX.$index);

            while (! is_file($ready)) {
                self::assertStillRunning($invoked, $barrier);

                if (microtime(true) > $deadline) {
                    touch(self::path($barrier, self::RELEASE));

                    throw new ProbeException('Probe '.$index.' never signalled readiness.');
                }

                usleep(self::POLL_MICROSECONDS);
            }
        }

        $ready = glob($barrier.'/'.self::READY_PREFIX.'*') ?: [];

        if (count($ready) !== $count) {
            touch(self::path($barrier, self::RELEASE));

            throw new ProbeException('The barrier did not see every probe become ready: '.implode(', ', $ready));
        }
    }

    private static function assertStillRunning(InvokedProcessPool $invoked, string $barrier): void
    {
        if (! $invoked->running()->isEmpty()) {
            return;
        }

        touch(self::path($barrier, self::RELEASE));

        $failures = collect($invoked->wait())->map->errorOutput()->implode("\n");

        throw new ProbeException('A probe exited before signalling readiness: '.$failures);
    }

    /**
     * @return list<array{status: int, body: mixed, cookie: ?string}>
     */
    private static function results(InvokedProcessPool $invoked): array
    {
        return collect($invoked->wait())->map(function ($result): array {
            if ($result->exitCode() !== 0) {
                throw new ProbeException('Parallel probe failed: '.$result->errorOutput().$result->output());
            }

            $decoded = json_decode($result->output(), true);

            if (! is_array($decoded)) {
                throw new ProbeException('Parallel probe returned no decodable payload: '.$result->output());
            }

            return $decoded;
        })->all();
    }

    private static function path(string $barrier, string $name): string
    {
        return $barrier.'/'.$name;
    }

    private static function cleanUp(string $barrier): void
    {
        foreach (glob($barrier.'/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($barrier);
    }
}
