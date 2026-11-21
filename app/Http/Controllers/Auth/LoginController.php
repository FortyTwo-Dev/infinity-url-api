<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\LoginUser;
use App\Http\Controllers\Concerns\RespondsWithSession;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\JsonResponse;
use LogicException;

final class LoginController
{
    use RespondsWithSession;

    public function __invoke(LoginRequest $request, LoginUser $action): JsonResponse
    {
        $data = $request->validated();
        $result = $action->handle((string) $data['email'], (string) $data['password']);

        if ($result->status === 'totp_required') {
            return response()->json([
                'status' => 'totp_required',
                'challenge' => $result->challenge,
            ]);
        }

        if ($result->tokens === null || $result->user === null) {
            throw new LogicException('An authenticated login result requires tokens and a user.');
        }

        return $this->sessionResponse($result->tokens, $result->user);
    }
}
