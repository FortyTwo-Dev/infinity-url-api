<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Models\User;
use App\Support\ApiData;
use App\Support\RefreshCookie;
use App\Support\SessionTokens;
use Illuminate\Http\JsonResponse;

trait RespondsWithSession
{
    private function sessionResponse(SessionTokens $tokens, User $user): JsonResponse
    {
        return response()->json([
            'access_token' => $tokens->accessToken,
            'token_type' => 'Bearer',
            'expires_in' => $tokens->expiresIn,
            'user' => ApiData::user($user),
        ])->withCookie(RefreshCookie::make($tokens->refreshToken));
    }

    private function refreshResponse(SessionTokens $tokens): JsonResponse
    {
        return response()->json([
            'access_token' => $tokens->accessToken,
            'token_type' => 'Bearer',
            'expires_in' => $tokens->expiresIn,
        ])->withCookie(RefreshCookie::make($tokens->refreshToken));
    }
}
