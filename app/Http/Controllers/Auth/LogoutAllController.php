<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Models\User;
use App\Services\TokenService;
use App\Support\RefreshCookie;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use LogicException;

final class LogoutAllController
{
    public function __invoke(Request $request, TokenService $tokens): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new LogicException('An authenticated user is required.');
        }

        $tokens->revokeAllRefresh($user);

        return response()->noContent()->withCookie(RefreshCookie::forget());
    }
}
