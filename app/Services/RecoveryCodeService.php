<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\RecoveryCode;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

final class RecoveryCodeService
{
    /**
     * Replace every recovery code and return the plain values once.
     *
     * @return list<string>
     */
    public function regenerate(User $user): array
    {
        $user->recoveryCodes()->delete();

        $codes = [];

        for ($index = 0; $index < (int) config('identity.tokens.recovery_codes'); $index++) {
            $code = Str::upper(Str::random(10));
            $codes[] = $code;

            RecoveryCode::query()->create([
                'user_id' => $user->id,
                'code_hash' => Hash::make($code),
            ]);
        }

        return $codes;
    }

    public function consume(User $user, string $code): bool
    {
        foreach ($user->recoveryCodes()->whereNull('used_at')->get() as $recoveryCode) {
            if (! Hash::check($code, $recoveryCode->code_hash)) {
                continue;
            }

            $updated = RecoveryCode::query()
                ->whereKey($recoveryCode->id)
                ->whereNull('used_at')
                ->update(['used_at' => now()]);

            return $updated === 1;
        }

        return false;
    }

    public function deleteAll(User $user): void
    {
        $user->recoveryCodes()->delete();
    }
}
