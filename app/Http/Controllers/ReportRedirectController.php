<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Response;

/**
 * The only path on the link domain that is not a redirect to a destination.
 * It answers 302 with no body and no cookie, and never counts a click.
 */
final class ReportRedirectController extends Controller
{
    public function __invoke(string $slug): Response
    {
        $base = rtrim((string) config('identity.dashboard_url'), '/');

        return response('', 302, [
            'Location' => $base.'/report?slug='.rawurlencode($slug),
            'Cache-Control' => 'no-store',
            'Referrer-Policy' => 'no-referrer',
            'X-Robots-Tag' => 'noindex',
        ]);
    }
}
