<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Exceptions\ProblemException;
use App\Http\Controllers\Concerns\RespondsWithSession;
use App\Services\TokenService;
use App\Support\RefreshCookie;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class RefreshController
{
    use RespondsWithSession;

    public function __invoke(Request $request, TokenService $tokens): JsonResponse
    {
        $cookie = $request->cookie(RefreshCookie::NAME);

        if (! is_string($cookie) || $cookie === '') {
            throw ProblemException::tokenRevoked();
        }

        return $this->refreshResponse($tokens->rotate($cookie));
    }
}
