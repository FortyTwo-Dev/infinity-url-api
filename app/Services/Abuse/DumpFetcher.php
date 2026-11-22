<?php

declare(strict_types=1);

namespace App\Services\Abuse;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * One full download of a public dump, once a day. Never a per-URL API call:
 * no client destination is ever sent to a third party (D-96).
 */
final class DumpFetcher
{
    /**
     * @return list<string>|null the raw lines, or null when the download failed
     */
    public function fetch(string $source): ?array
    {
        $config = config('abuse.sources.'.$source);

        if (! is_array($config) || ! isset($config['url'])) {
            return null;
        }

        try {
            $response = $this->request($config['auth_key'] ?? null)
                ->get((string) $config['url']);

            if (! $response->successful()) {
                report(new \RuntimeException('Abuse dump '.$source.' answered '.$response->status()));

                return null;
            }

            return preg_split('/\r\n|\r|\n/', $response->body()) ?: [];
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    private function request(?string $authKey): PendingRequest
    {
        $request = Http::timeout(30)->withHeaders(['Accept' => 'text/plain']);

        return is_string($authKey) && $authKey !== ''
            ? $request->withHeaders(['Auth-Key' => $authKey])
            : $request;
    }
}
