<?php

declare(strict_types=1);

namespace App\Providers;

use App\Exceptions\UnsupportedCacheRepositoryException;
use App\Models\PersonalAccessToken;
use App\Services\Abuse\PublicSuffix;
use App\Services\Analytics\AnalyticsSalt;
use App\Services\Analytics\PendingClicks;
use App\Services\EmailHasher;
use Dedoc\Scramble\Scramble;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(AnalyticsSalt::class);
        $this->app->singleton(PendingClicks::class);
        $this->app->singleton(PublicSuffix::class);

        $this->app->bind(Repository::class, function (Application $app): Repository {
            $repository = $app->make(CacheRepository::class);

            if (! $repository instanceof Repository) {
                throw new UnsupportedCacheRepositoryException('The default cache store must be an Illuminate cache repository.');
            }

            return $repository;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);
        JsonResource::withoutWrapping();

        $this->registerRateLimiters();
        $this->registerScrambleRoutes();

        $this->app->terminating(function (): void {
            $this->app->make(PendingClicks::class)->flush();
        });
    }

    private function registerRateLimiters(): void
    {
        RateLimiter::for('link-create', static function (Request $request): Limit {
            return Limit::perMinute(30)->by('user:'.(string) $request->user()?->getAuthIdentifier());
        });

        RateLimiter::for('token-create', static function (Request $request): Limit {
            return Limit::perHour(10)->by('user:'.(string) $request->user()?->getAuthIdentifier());
        });

        RateLimiter::for('login', function (Request $request): Limit {
            $email = EmailHasher::hash((string) $request->input('email', ''));

            return Limit::perMinutes(15, 10)
                ->by($email.'|'.$this->fingerprint((string) $request->ip()));
        });

        RateLimiter::for('totp', function (Request $request): Limit {
            $challenge = hash('sha256', (string) $request->input('challenge', ''));

            return Limit::perMinutes(15, 5)
                ->by($challenge.'|'.$this->fingerprint((string) $request->ip()));
        });

        RateLimiter::for('verification-resend', static function (Request $request): Limit {
            return Limit::perHour(3)
                ->by(EmailHasher::hash((string) $request->input('email', '')));
        });

        RateLimiter::for('password-forgot', static function (Request $request): Limit {
            return Limit::perHour(3)
                ->by(EmailHasher::hash((string) $request->input('email', '')));
        });

        RateLimiter::for('register', function (Request $request): Limit {
            return Limit::perDay(2)
                ->by($this->fingerprint((string) $request->ip()));
        });
    }

    private function registerScrambleRoutes(): void
    {
        Scramble::configure()->expose(
            ui: static function (Router $router, mixed $action) {
                $router->get('docs', $action)->name('scramble.docs.ui');

                return $router->get('docs/api', $action)->name('scramble.docs.ui.legacy');
            },
            document: static function (Router $router, mixed $action) {
                $router->get('openapi.json', $action)->name('scramble.docs.document');

                return $router->get('docs/api.json', $action)->name('scramble.docs.document.legacy');
            },
        );
    }

    private function fingerprint(string $value): string
    {
        return hash_hmac('sha256', $value, (string) config('app.key'));
    }
}
