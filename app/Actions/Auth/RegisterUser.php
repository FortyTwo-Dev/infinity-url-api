<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Exceptions\ProblemException;
use App\Models\User;
use App\Models\Workspace;
use App\Services\EmailHasher;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class RegisterUser
{
    public function __construct(private readonly SendVerificationEmail $sendVerification) {}

    public function handle(string $email, string $password): void
    {
        if (! (bool) config('identity.signups_open', false)) {
            throw ProblemException::signupsClosed();
        }

        $existing = User::withTrashed()
            ->where('email_hash', EmailHasher::hash($email))
            ->first();

        if ($existing !== null) {
            if (! $existing->trashed() && ! $existing->isVerified()) {
                $this->sendVerification->handle($existing);
            }

            return;
        }

        try {
            $user = DB::transaction(function () use ($email, $password): User {
                $user = User::query()->create([
                    'email' => $email,
                    'password_hash' => $password,
                ]);

                $workspace = Workspace::query()->create([
                    'name' => 'My workspace',
                    'slug' => Str::lower((string) Str::ulid()),
                    'settings' => [],
                ]);

                $workspace->members()->attach($user->id, ['role' => 'owner']);

                return $user;
            });
        } catch (QueryException $exception) {
            if ($this->isDuplicate($exception)) {
                return;
            }

            throw $exception;
        }

        $this->sendVerification->handle($user);
    }

    private function isDuplicate(QueryException $exception): bool
    {
        return ($exception->errorInfo[0] ?? null) === '23505'
            || str_contains($exception->getMessage(), '23505');
    }
}
