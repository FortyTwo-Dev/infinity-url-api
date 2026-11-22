<?php

use App\Services\RedirectCache;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

uses(TestCase::class);

it('cannot acquire a lock when the store provides none', function () {
    $store = new class implements Store
    {
        public function get($key)
        {
            return null;
        }

        public function many(array $keys)
        {
            return [];
        }

        public function put($key, $value, $seconds)
        {
            return false;
        }

        public function putMany(array $values, $seconds)
        {
            return false;
        }

        public function increment($key, $value = 1)
        {
            return 0;
        }

        public function decrement($key, $value = 1)
        {
            return 0;
        }

        public function forever($key, $value)
        {
            return false;
        }

        public function touch($key, $seconds)
        {
            return false;
        }

        public function forget($key)
        {
            return false;
        }

        public function flush()
        {
            return false;
        }

        public function getPrefix()
        {
            return '';
        }
    };

    $cache = new RedirectCache(new Repository($store));

    expect($cache->acquire('iurl.fr', 'nolock1'))->toBeNull();
});

it('cannot acquire a lock that is already held', function () {
    $repository = Cache::store('array');

    $held = $repository->getStore()->lock(RedirectCache::key('iurl.fr', 'contended1').':lock', 10);

    expect($held->get())->toBeTrue();

    config(['redirect.lock.wait' => 0.001]);

    try {
        expect((new RedirectCache($repository))->acquire('iurl.fr', 'contended1'))->toBeNull();
    } finally {
        $held->release();
    }
});

it('swallows a failing lock release', function () {
    $lock = new class implements Lock
    {
        public function get($callback = null)
        {
            return true;
        }

        public function block($seconds, $callback = null)
        {
            return true;
        }

        public function release()
        {
            throw new RuntimeException('release failed');
        }

        public function owner()
        {
            return 'test-owner';
        }

        public function forceRelease()
        {
            //
        }
    };

    $cache = new RedirectCache(Cache::store('array'));

    $result = $cache->release($lock);

    expect($result)->toBeNull();
});
