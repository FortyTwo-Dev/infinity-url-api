<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\VerifyEmail;
use App\Http\Requests\Auth\VerifyEmailRequest;
use Illuminate\Http\Response;

final class VerifyEmailController
{
    public function __invoke(VerifyEmailRequest $request, VerifyEmail $action): Response
    {
        $data = $request->validated();
        $action->handle((string) $data['token']);

        return response('', 200);
    }
}
