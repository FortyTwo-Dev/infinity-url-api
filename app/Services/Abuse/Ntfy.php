<?php

declare(strict_types=1);

namespace App\Services\Abuse;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * A single POST to the self-hosted ntfy topic. Best effort: a failed alert
 * never fails the work that triggered it.
 */
final class Ntfy
{
    public function send(string $title, string $message): void
    {
        $url = config('abuse.ntfy_url');

        if (! is_string($url) || $url === '') {
            return;
        }

        try {
            Http::timeout(3)
                ->withHeaders(['Title' => $title])
                ->withBody($message, 'text/plain')
                ->post($url);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
