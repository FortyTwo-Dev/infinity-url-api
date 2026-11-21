<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Services\TokenService;
use App\Support\RefreshCookie;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class LogoutController
{
    public function __invoke(Request $request, TokenService $tokens): Response
    {
        $cookie = $request->cookie(RefreshCookie::NAME);

        if (is_string($cookie) && $cookie !== '') {
            $tokens->revokeCurrent($cookie);
        }

        return response()->noContent()->withCookie(RefreshCookie::forget());
    }
}
