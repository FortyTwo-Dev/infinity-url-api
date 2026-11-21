<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\ResendVerificationEmail;
use App\Http\Requests\Auth\ResendVerificationRequest;
use Illuminate\Http\Response;

final class ResendVerificationController
{
    public function __invoke(ResendVerificationRequest $request, ResendVerificationEmail $action): Response
    {
        $data = $request->validated();
        $action->handle((string) $data['email']);

        return response('', 202);
    }
}
