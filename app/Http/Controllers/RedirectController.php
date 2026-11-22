<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Analytics\CaptureClick;
use App\Exceptions\RedirectUnavailableException;
use App\Services\ErrorPages;
use App\Services\ResolveLink;
use App\Support\NoStoreHeaderBag;
use App\Support\RedirectResult;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use LogicException;

final class RedirectController extends Controller
{
    public function __invoke(
        Request $request,
        string $slug,
        ResolveLink $resolver,
        ErrorPages $errors,
        CaptureClick $capture,
    ): Response {
        try {
            $result = $resolver->resolve($request->getHost(), $slug);
        } catch (RedirectUnavailableException) {
            return response('', 503);
        }

        $response = match ($result->status) {
            302 => $this->redirect($result),
            403 => $errors->blocked(),
            410 => $result->reason === 'expired'
                ? $errors->goneExpired()
                : $errors->goneDeleted(),
            default => $errors->notFound(),
        };

        if ($result->status === 302) {
            $capture->handle($request, $result, CarbonImmutable::now('UTC'));
        }

        return $response;
    }

    private function redirect(RedirectResult $result): Response
    {
        if ($result->location === null) {
            throw new LogicException('A redirect response requires a location.');
        }

        $response = response('', 302);

        $response->headers = new NoStoreHeaderBag([
            'Location' => $result->location,
            'Cache-Control' => 'no-store',
            'Referrer-Policy' => 'no-referrer',
            'X-Robots-Tag' => 'noindex',
        ]);

        return $response;
    }
}
