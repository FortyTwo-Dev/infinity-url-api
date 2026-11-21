<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Link;
use App\Services\RedirectCache;

final class LinkObserver
{
    public function __construct(private readonly RedirectCache $cache) {}

    public function saved(Link $link): void
    {
        $this->forget($link);
    }

    public function deleted(Link $link): void
    {
        $this->forget($link);
    }

    public function restored(Link $link): void
    {
        $this->forget($link);
    }

    public function forceDeleted(Link $link): void
    {
        $this->forget($link);
    }

    private function forget(Link $link): void
    {
        $host = $link->domain()->value('hostname');

        if (! is_string($host)) {
            return;
        }

        $this->cache->forget($host, $link->slug);

        $original = $link->getOriginal('slug');

        if (is_string($original) && $original !== $link->slug) {
            $this->cache->forget($host, $original);
        }
    }
}
