<?php

declare(strict_types=1);

namespace App\Support;

use Symfony\Component\HttpFoundation\ResponseHeaderBag;

final class NoStoreHeaderBag extends ResponseHeaderBag
{
    protected function computeCacheControlValue(): string
    {
        $value = $this->getCacheControlHeader();

        if ($value === 'no-store') {
            return $value;
        }

        return parent::computeCacheControlValue();
    }
}
