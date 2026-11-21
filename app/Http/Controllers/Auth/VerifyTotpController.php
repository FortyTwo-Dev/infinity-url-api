<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\VerifyTotpChallenge;
use App\Http\Controllers\Concerns\RespondsWithSession;
use App\Http\Requests\Auth\VerifyTotpRequest;
use Illuminate\Http\JsonResponse;
use LogicException;

final class VerifyTotpController
{
    use RespondsWithSession;

    public function __invoke(VerifyTotpRequest $request, VerifyTotpChallenge $action): JsonResponse
    {
        $data = $request->validated();

        $result = $action->handle(
            (string) $data['challenge'],
            isset($data['code']) ? (string) $data['code'] : null,
            isset($data['recovery_code']) ? (string) $data['recovery_code'] : null,
        );

        if ($result->tokens === null || $result->user === null) {
            throw new LogicException('A verified challenge requires tokens and a user.');
        }

        return $this->sessionResponse($result->tokens, $result->user);
    }
}
