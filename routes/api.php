<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LogoutAllController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Auth\RefreshController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Auth\ResendVerificationController;
use App\Http\Controllers\Auth\ResetPasswordController;
use App\Http\Controllers\Auth\TwoFactorController;
use App\Http\Controllers\Auth\VerifyEmailController;
use App\Http\Controllers\Auth\VerifyTotpController;
use App\Http\Controllers\LinkController;
use App\Http\Controllers\MeController;
use App\Http\Controllers\TokenController;
use App\Http\Controllers\WorkspaceController;
use Illuminate\Support\Facades\Route;

$linkUri = 'links/{id}';

Route::prefix('auth')->group(function (): void {
    Route::post('register', RegisterController::class)->middleware('throttle:register');
    Route::post('verify-email', VerifyEmailController::class);
    Route::post('verification/resend', ResendVerificationController::class)
        ->middleware('throttle:verification-resend');
    Route::post('login', LoginController::class)->middleware('throttle:login');
    Route::post('2fa/verify', VerifyTotpController::class)->middleware('throttle:totp');
    Route::post('refresh', RefreshController::class);
    Route::post('logout', LogoutController::class);
    Route::post('password/forgot', ForgotPasswordController::class)
        ->middleware('throttle:password-forgot');
    Route::post('password/reset', ResetPasswordController::class);
});

Route::middleware('auth:sanctum')->group(function () use ($linkUri): void {
    Route::get('me', [MeController::class, 'show'])->middleware('abilities:me:read');
    Route::get('me/export', [MeController::class, 'export'])->middleware('abilities:me:read');
    Route::patch('me/password', [MeController::class, 'password'])->middleware('abilities:me:write');
    Route::delete('me', [MeController::class, 'destroy'])->middleware('abilities:me:write');
    Route::get('workspaces', [WorkspaceController::class, 'index'])->middleware('abilities:me:read');

    Route::post('auth/2fa/enable', [TwoFactorController::class, 'enable'])
        ->middleware('abilities:me:write');
    Route::post('auth/2fa/confirm', [TwoFactorController::class, 'confirm'])
        ->middleware('abilities:me:write');
    Route::post('auth/2fa/disable', [TwoFactorController::class, 'disable'])
        ->middleware('abilities:me:write');
    Route::post('auth/2fa/recovery-codes', [TwoFactorController::class, 'recoveryCodes'])
        ->middleware('abilities:me:write');
    Route::post('auth/logout-all', LogoutAllController::class)->middleware('abilities:me:write');

    Route::get('links', [LinkController::class, 'index'])->middleware('abilities:links:read');
    Route::post('links', [LinkController::class, 'store'])
        ->middleware(['abilities:links:write', 'throttle:link-create']);
    Route::get($linkUri, [LinkController::class, 'show'])->middleware('abilities:links:read');
    Route::patch($linkUri, [LinkController::class, 'update'])->middleware('abilities:links:write');
    Route::delete($linkUri, [LinkController::class, 'destroy'])->middleware('abilities:links:write');

    Route::get('tokens', [TokenController::class, 'index'])->middleware('abilities:tokens:read');
    Route::post('tokens', [TokenController::class, 'store'])
        ->middleware(['abilities:tokens:write', 'throttle:token-create']);
    Route::delete('tokens/{id}', [TokenController::class, 'destroy'])->middleware('abilities:tokens:write');
});
