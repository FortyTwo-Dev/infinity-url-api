<?php

use App\Mail\ResetPassword;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Process\Pool;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Process;
use PragmaRX\Google2FA\Google2FA;

/**
 * Why the configuration guard exists: a spawned probe boots the application in
 * its own process. If `config:cache` is in place, the child would read the
 * cached configuration (possibly the development database) and ignore the
 * environment passed below. In that case the test is skipped instead of
 * touching anything outside the test database.
 */
function concurrentProbesUnavailableReason(): ?string
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
 * Run HTTP requests in parallel PHP processes, each booting the application and
 * dispatching a real kernel request against the test database and Valkey.
 *
 * Every probe bootstraps the application, announces readiness, then waits for
 * one common release file so the requests are dispatched inside the same
 * window. The barrier directory is pinned through the child environment.
 *
 * @param  list<array{method?: string, url: string, body?: array<string, mixed>, cookies?: array<string, string>, headers?: array<string, string>}>  $requests
 * @return list<array{status: int, body: mixed, cookie: ?string}>
 */
function runConcurrentAuthRequests(array $requests): array
{
    $barrier = sys_get_temp_dir().'/iurl_barrier_'.bin2hex(random_bytes(8));

    if (! mkdir($barrier, 0700)) {
        throw new RuntimeException('Could not create the probe barrier directory: '.$barrier);
    }

    $script = $barrier.'/probe.php';

    file_put_contents($script, <<<'PHP'
<?php

$id = $argv[1];
$base = $argv[2];
$method = $argv[3];
$url = $argv[4];
$body = $argv[5];
$cookies = $argv[6];
$headers = $argv[7];

$barrier = (string) getenv('IURL_PROBE_BARRIER');

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
touch($barrier.'/ready-'.$id);

$deadline = microtime(true) + 30;

while (! is_file($barrier.'/release')) {
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
PHP);

    $connection = config('database.connections.'.config('database.default'));
    $redis = config('database.redis.default');

    $environment = [
        'APP_ENV' => 'testing',
        'APP_KEY' => (string) config('app.key'),
        'BLIND_INDEX_KEY' => (string) config('identity.blind_index_key'),
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
    ];

    try {
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

        $invoked = Process::pool(function (Pool $pool) use ($commands, $environment): void {
            foreach ($commands as $command) {
                $pool->path(base_path())->timeout(60)->env($environment)->command($command);
            }
        })->start();

        $deadline = microtime(true) + 30;

        foreach (array_keys($requests) as $index) {
            $ready = $barrier.'/ready-'.$index;

            while (! is_file($ready)) {
                if ($invoked->running()->isEmpty()) {
                    touch($barrier.'/release');

                    $failures = collect($invoked->wait())->map->errorOutput()->implode("\n");

                    throw new RuntimeException('A probe exited before signalling readiness: '.$failures);
                }

                if (microtime(true) > $deadline) {
                    touch($barrier.'/release');

                    throw new RuntimeException('Probe '.$index.' never signalled readiness.');
                }

                usleep(2000);
            }
        }

        // Every probe is booted and parked: release them together.
        $ready = glob($barrier.'/ready-*') ?: [];

        if (count($ready) !== count($requests)) {
            touch($barrier.'/release');

            throw new RuntimeException('The barrier did not see every probe become ready: '.implode(', ', $ready));
        }

        touch($barrier.'/release');

        return collect($invoked->wait())->map(function ($result): array {
            if ($result->exitCode() !== 0) {
                throw new RuntimeException('Parallel probe failed: '.$result->errorOutput().$result->output());
            }

            $decoded = json_decode($result->output(), true);

            if (! is_array($decoded)) {
                throw new RuntimeException('Parallel probe returned no decodable payload: '.$result->output());
            }

            return $decoded;
        })->all();
    } finally {
        foreach (glob($barrier.'/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($barrier);
    }
}

it('lets exactly one of two simultaneous refresh attempts rotate the cookie', function () {
    if ($reason = concurrentProbesUnavailableReason()) {
        $this->markTestSkipped($reason);
    }

    $workspace = Workspace::factory()->create();
    $user = User::factory()->create(['email' => 'concurrent-refresh@example.com']);
    $workspace->members()->attach($user->id, ['role' => 'owner']);

    $login = $this->postJson('https://api.url.infinityproject.fr/v1/auth/login', [
        'email' => 'concurrent-refresh@example.com',
        'password' => 'password',
    ]);
    $login->assertStatus(200);
    $cookie = $login->getCookie('__Host-iurl_refresh', false)->getValue();

    $results = runConcurrentAuthRequests([
        [
            'url' => 'https://api.url.infinityproject.fr/v1/auth/refresh',
            'cookies' => ['__Host-iurl_refresh' => $cookie],
        ],
        [
            'url' => 'https://api.url.infinityproject.fr/v1/auth/refresh',
            'cookies' => ['__Host-iurl_refresh' => $cookie],
        ],
    ]);

    $statuses = array_column($results, 'status');
    sort($statuses);

    expect($statuses)->toBe([200, 401]);
    expect(count(array_filter(array_column($results, 'status'), fn (int $status): bool => $status === 200)))->toBe(1);

    $winner = collect($results)->firstWhere('status', 200);
    $loser = collect($results)->firstWhere('status', 401);

    expect($loser['body']['code'])->toBe('token_revoked');
    expect($winner['cookie'])->toBeString()->not->toBe('');

    // The replay revoked the whole family, including the token the winner got.
    $this->withCredentials()
        ->withUnencryptedCookie('__Host-iurl_refresh', $winner['cookie'])
        ->postJson('https://api.url.infinityproject.fr/v1/auth/refresh')
        ->assertStatus(401);
});

it('lets exactly one of two simultaneous totp verifications consume the code', function () {
    if ($reason = concurrentProbesUnavailableReason()) {
        $this->markTestSkipped($reason);
    }

    $google2fa = new Google2FA;
    $secret = $google2fa->generateSecretKey();

    $workspace = Workspace::factory()->create();
    $user = User::factory()->create(['email' => 'concurrent-totp@example.com']);
    $workspace->members()->attach($user->id, ['role' => 'owner']);
    $user->forceFill(['totp_secret' => $secret, 'totp_confirmed_at' => now()])->save();

    $login = $this->postJson('https://api.url.infinityproject.fr/v1/auth/login', [
        'email' => 'concurrent-totp@example.com',
        'password' => 'password',
    ]);
    $login->assertStatus(200);
    expect($login->json('status'))->toBe('totp_required');

    $challenge = $login->json('challenge');
    $code = $google2fa->getCurrentOtp($secret);

    $results = runConcurrentAuthRequests([
        [
            'url' => 'https://api.url.infinityproject.fr/v1/auth/2fa/verify',
            'body' => ['challenge' => $challenge, 'code' => $code],
        ],
        [
            'url' => 'https://api.url.infinityproject.fr/v1/auth/2fa/verify',
            'body' => ['challenge' => $challenge, 'code' => $code],
        ],
    ]);

    $statuses = array_column($results, 'status');
    sort($statuses);

    expect(count(array_filter(array_column($results, 'status'), fn (int $status): bool => $status === 200)))->toBe(1);
    expect($statuses[0])->toBe(200);
    expect($statuses[1])->toBeIn([422, 503]);

    $loser = collect($results)->first(fn (array $result): bool => $result['status'] !== 200);

    if ($loser['status'] === 422) {
        expect($loser['body']['code'])->toBe('totp_invalid');
    }

    $winner = collect($results)->firstWhere('status', 200);
    expect($winner['body']['access_token'])->toBeString()->not->toBe('');
});

it('lets exactly one of two simultaneous password resets consume the token', function () {
    if ($reason = concurrentProbesUnavailableReason()) {
        $this->markTestSkipped($reason);
    }

    Mail::fake();

    $workspace = Workspace::factory()->create();
    $user = User::factory()->create(['email' => 'concurrent-reset@example.com']);
    $workspace->members()->attach($user->id, ['role' => 'owner']);

    $this->postJson('https://api.url.infinityproject.fr/v1/auth/password/forgot', [
        'email' => 'concurrent-reset@example.com',
    ])->assertStatus(202);

    $resetUrl = Mail::sent(ResetPassword::class)->firstOrFail()->url;
    parse_str((string) parse_url($resetUrl, PHP_URL_QUERY), $query);
    $token = $query['token'] ?? '';
    expect($token)->toBeString()->not->toBe('');

    $results = runConcurrentAuthRequests([
        [
            'url' => 'https://api.url.infinityproject.fr/v1/auth/password/reset',
            'body' => ['token' => $token, 'password' => 'concurrent password one'],
        ],
        [
            'url' => 'https://api.url.infinityproject.fr/v1/auth/password/reset',
            'body' => ['token' => $token, 'password' => 'concurrent password two'],
        ],
    ]);

    $statuses = array_column($results, 'status');
    sort($statuses);

    expect($statuses)->toBe([204, 422]);

    $loser = collect($results)->firstWhere('status', 422);
    expect($loser['body']['code'])->toBe('validation_failed');
    expect($loser['body']['errors'])->toHaveKey('token');

    $one = $this->postJson('https://api.url.infinityproject.fr/v1/auth/login', [
        'email' => 'concurrent-reset@example.com',
        'password' => 'concurrent password one',
    ])->status();

    $two = $this->postJson('https://api.url.infinityproject.fr/v1/auth/login', [
        'email' => 'concurrent-reset@example.com',
        'password' => 'concurrent password two',
    ])->status();

    $logins = [$one, $two];
    sort($logins);
    expect($logins)->toBe([200, 401]);

    $replay = $this->postJson('https://api.url.infinityproject.fr/v1/auth/password/reset', [
        'token' => $token,
        'password' => 'concurrent password three',
    ]);

    $replay->assertStatus(422);
    expect($replay->json('code'))->toBe('validation_failed');
    expect($replay->json('errors'))->toHaveKey('token');

    expect(Cache::get('password-reset:'.$user->public_id))->toBeNull();
});

it('answers token_revoked for two consumed cookies of the same family replayed in parallel', function () {
    if ($reason = concurrentProbesUnavailableReason()) {
        $this->markTestSkipped($reason);
    }

    $workspace = Workspace::factory()->create();
    $user = User::factory()->create(['email' => 'concurrent-family@example.com']);
    $workspace->members()->attach($user->id, ['role' => 'owner']);

    $login = $this->postJson('https://api.url.infinityproject.fr/v1/auth/login', [
        'email' => 'concurrent-family@example.com',
        'password' => 'password',
    ]);
    $login->assertStatus(200);
    $cookieA = $login->getCookie('__Host-iurl_refresh', false)->getValue();

    $this->withCredentials();

    $first = $this->withUnencryptedCookie('__Host-iurl_refresh', $cookieA)
        ->postJson('https://api.url.infinityproject.fr/v1/auth/refresh');
    $first->assertStatus(200);
    $cookieB = $first->getCookie('__Host-iurl_refresh', false)->getValue();

    $second = $this->withUnencryptedCookie('__Host-iurl_refresh', $cookieB)
        ->postJson('https://api.url.infinityproject.fr/v1/auth/refresh');
    $second->assertStatus(200);
    $cookieC = $second->getCookie('__Host-iurl_refresh', false)->getValue();

    $results = runConcurrentAuthRequests([
        [
            'url' => 'https://api.url.infinityproject.fr/v1/auth/refresh',
            'cookies' => ['__Host-iurl_refresh' => $cookieA],
        ],
        [
            'url' => 'https://api.url.infinityproject.fr/v1/auth/refresh',
            'cookies' => ['__Host-iurl_refresh' => $cookieB],
        ],
    ]);

    $statuses = array_column($results, 'status');
    sort($statuses);

    expect($statuses)->toBe([401, 401]);

    foreach ($results as $result) {
        expect($result['body']['code'])->toBe('token_revoked');
    }

    // Both consumed tokens were replayed, so the third (still active) token of
    // the family must be dead as well.
    $this->withUnencryptedCookie('__Host-iurl_refresh', $cookieC)
        ->postJson('https://api.url.infinityproject.fr/v1/auth/refresh')
        ->assertStatus(401);
});

it('serialises a concurrent 2fa disable and login verification on the same step', function () {
    if ($reason = concurrentProbesUnavailableReason()) {
        $this->markTestSkipped($reason);
    }

    $google2fa = new Google2FA;
    $secret = $google2fa->generateSecretKey();

    $workspace = Workspace::factory()->create();
    $user = User::factory()->create(['email' => 'concurrent-disable@example.com']);
    $workspace->members()->attach($user->id, ['role' => 'owner']);
    $user->forceFill(['totp_secret' => $secret, 'totp_confirmed_at' => now()])->save();

    $access = $user->createToken('dashboard', ['*'])->plainTextToken;

    $login = $this->postJson('https://api.url.infinityproject.fr/v1/auth/login', [
        'email' => 'concurrent-disable@example.com',
        'password' => 'password',
    ]);
    $login->assertStatus(200);
    expect($login->json('status'))->toBe('totp_required');
    $challenge = $login->json('challenge');

    $code = $google2fa->getCurrentOtp($secret);

    $results = runConcurrentAuthRequests([
        [
            'url' => 'https://api.url.infinityproject.fr/v1/auth/2fa/disable',
            'headers' => ['Authorization' => 'Bearer '.$access],
            'body' => ['password' => 'password', 'code' => $code],
        ],
        [
            'url' => 'https://api.url.infinityproject.fr/v1/auth/2fa/verify',
            'body' => ['challenge' => $challenge, 'code' => $code],
        ],
    ]);

    $statuses = array_column($results, 'status');
    sort($statuses);

    expect(in_array(503, $statuses, true))->toBeFalse();

    $accepted = collect($results)->filter(fn (array $result): bool => in_array($result['status'], [200, 204], true))->values();
    $rejected = collect($results)->filter(fn (array $result): bool => $result['status'] === 422)->values();

    expect($accepted)->toHaveCount(1);
    expect($rejected)->toHaveCount(1);
    expect($rejected[0]['body']['code'])->toBe('totp_invalid');

    $fresh = User::query()->findOrFail($user->id);

    if ($accepted[0]['status'] === 204) {
        expect($fresh->totp_confirmed_at)->toBeNull();
        expect($fresh->totp_secret)->toBeNull();
        expect(DB::table('recovery_codes')->where('user_id', $user->id)->count())->toBe(0);
    } else {
        expect($fresh->totp_confirmed_at)->not->toBeNull();
        expect($fresh->totp_secret)->not->toBeNull();
    }
});
