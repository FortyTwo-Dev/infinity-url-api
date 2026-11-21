<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\RegisterUser;
use App\Http\Requests\Auth\RegisterRequest;
use Illuminate\Http\Response;

final class RegisterController
{
    public function __invoke(RegisterRequest $request, RegisterUser $action): Response
    {
        $data = $request->validated();

        $action->handle((string) $data['email'], (string) $data['password']);

        return response('', 201);
    }
}
