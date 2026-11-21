<?php

declare(strict_types=1);

namespace App\Providers;

use App\Exceptions\UnsupportedCacheRepositoryException;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
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
        //
    }
}
