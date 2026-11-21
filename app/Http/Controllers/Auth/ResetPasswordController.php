<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\ResetPassword;
use App\Http\Requests\Auth\ResetPasswordRequest;
use Illuminate\Http\Response;

final class ResetPasswordController
{
    public function __invoke(ResetPasswordRequest $request, ResetPassword $action): Response
    {
        $data = $request->validated();
        $action->handle((string) $data['token'], (string) $data['password']);

        return response()->noContent();
    }
}
