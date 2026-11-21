<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\RequestPasswordReset;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use Illuminate\Http\Response;

final class ForgotPasswordController
{
    public function __invoke(ForgotPasswordRequest $request, RequestPasswordReset $action): Response
    {
        $data = $request->validated();
        $action->handle((string) $data['email']);

        return response('', 202);
    }
}
