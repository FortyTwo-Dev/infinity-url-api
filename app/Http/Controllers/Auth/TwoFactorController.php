<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\ConfirmTotp;
use App\Actions\Auth\DisableTotp;
use App\Actions\Auth\EnableTotp;
use App\Actions\Auth\RegenerateRecoveryCodes;
use App\Http\Requests\Auth\ConfirmTotpRequest;
use App\Http\Requests\Auth\DisableTotpRequest;
use App\Http\Requests\Auth\RecoveryCodesRequest;
use App\Models\User;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use LogicException;

final class TwoFactorController
{
    /**
     * @return array{secret: string, otpauth_url: string}
     */
    #[OpenApiResponse(status: 200, type: 'array{secret: string, otpauth_url: string}')]
    public function enable(Request $request, EnableTotp $action): array
    {
        return $action->handle($this->user($request));
    }

    /**
     * @return array{recovery_codes: list<string>}
     */
    #[OpenApiResponse(status: 200, type: 'array{recovery_codes: list<string>}')]
    public function confirm(ConfirmTotpRequest $request, ConfirmTotp $action): array
    {
        $data = $request->validated();

        return $action->handle($this->user($request), (string) $data['code']);
    }

    #[OpenApiResponse(status: 204, description: 'Two-factor authentication disabled.')]
    public function disable(DisableTotpRequest $request, DisableTotp $action): Response
    {
        $data = $request->validated();

        $action->handle(
            $this->user($request),
            (string) $data['password'],
            (string) $data['code'],
        );

        return response()->noContent();
    }

    /**
     * @return array{recovery_codes: list<string>}
     */
    #[OpenApiResponse(status: 200, type: 'array{recovery_codes: list<string>}')]
    public function recoveryCodes(RecoveryCodesRequest $request, RegenerateRecoveryCodes $action): array
    {
        $data = $request->validated();

        return $action->handle(
            $this->user($request),
            (string) $data['password'],
            (string) $data['code'],
        );
    }

    private function user(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new LogicException('An authenticated user is required.');
        }

        return $user;
    }
}
