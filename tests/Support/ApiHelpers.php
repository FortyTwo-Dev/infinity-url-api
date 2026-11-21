<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Mail\ResetPassword;
use App\Mail\VerifyEmail;
use App\Models\Domain;
use App\Models\Link;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\LinkDomainSeeder;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PragmaRX\Google2FA\Google2FA;

trait ApiHelpers
{
    /**
     * Create a workspace with one owner and return both.
     *
     * @param  array<string, mixed>  $workspaceAttributes
     * @return array{0: Workspace, 1: User}
     */
    protected function account(?string $email = null, array $workspaceAttributes = []): array
    {
        $workspace = Workspace::factory()->create($workspaceAttributes);
        $user = User::factory()->create($email === null ? [] : ['email' => $email]);
        $workspace->members()->attach($user->id, ['role' => 'owner']);

        return [$workspace, $user];
    }

    protected function login(string $email, string $password = 'password'): TestResponse
    {
        $response = $this->postJson('https://api.url.infinityproject.fr/v1/auth/login', [
            'email' => $email,
            'password' => $password,
        ]);

        $response->assertStatus(200);

        return $response;
    }

    protected function loginSession(string $email, string $password = 'password'): Session
    {
        $response = $this->login($email, $password);
        $cookie = $response->getCookie('__Host-iurl_refresh', false)?->getValue();

        expect($cookie)->toBeString()->not->toBe('');

        return new Session(
            (string) $response->json('access_token'),
            (string) $cookie,
        );
    }

    /**
     * Start a login that requires a TOTP step and return its challenge.
     */
    protected function challenge(string $email, string $password = 'password'): string
    {
        $response = $this->postJson('https://api.url.infinityproject.fr/v1/auth/login', [
            'email' => $email,
            'password' => $password,
        ]);

        $response->assertStatus(200);
        expect($response->json('status'))->toBe('totp_required');
        expect($response->json('challenge'))->toBeString()->not->toBe('');

        return (string) $response->json('challenge');
    }

    protected function withRefreshCookie(string $cookie): static
    {
        return $this->withCredentials()->withUnencryptedCookie('__Host-iurl_refresh', $cookie);
    }

    protected function refreshWith(string $cookie): TestResponse
    {
        return $this->withRefreshCookie($cookie)
            ->postJson('https://api.url.infinityproject.fr/v1/auth/refresh');
    }

    /**
     * Enable TOTP as the me:write agent and return the enable response.
     */
    protected function enableTotp(User $user): TestResponse
    {
        Sanctum::actingAs($user, ['me:write']);

        $response = $this->postJson('https://api.url.infinityproject.fr/v1/auth/2fa/enable');
        $response->assertStatus(200);

        expect($response->json('secret'))->toBeString()->not->toBe('');

        return $response;
    }

    protected function confirmTotp(string $secret): TestResponse
    {
        $response = $this->postJson('https://api.url.infinityproject.fr/v1/auth/2fa/confirm', [
            'code' => $this->totpCode($secret),
        ]);

        $response->assertStatus(200);

        return $response;
    }

    /**
     * Create an account whose TOTP is already confirmed, without going through
     * the enable/confirm flow (so the current step has not been consumed).
     *
     * @return array{0: Workspace, 1: User, 2: string}
     */
    protected function totpAccount(string $email): array
    {
        [$workspace, $user] = $this->account($email);

        $secret = $this->newTotpSecret();

        $user->forceFill([
            'totp_secret' => $secret,
            'totp_confirmed_at' => now(),
        ])->save();

        return [$workspace, $user, $secret];
    }

    protected function newTotpSecret(): string
    {
        return (new Google2FA)->generateSecretKey();
    }

    protected function totpCode(string $secret): string
    {
        return (new Google2FA)->getCurrentOtp($secret);
    }

    protected function nextTotpCode(string $secret): string
    {
        $google2fa = new Google2FA;

        return $google2fa->oathTotp($secret, $google2fa->getTimestamp() + 1);
    }

    protected function assertProblem(TestResponse $response, int $status, string $code): void
    {
        $response->assertStatus($status);
        expect((string) $response->headers->get('Content-Type'))->toStartWith('application/problem+json');
        expect($response->json('code'))->toBe($code);
    }

    protected function requestVerification(string $email): void
    {
        $this->postJson('https://api.url.infinityproject.fr/v1/auth/verification/resend', [
            'email' => $email,
        ])->assertStatus(202);
    }

    protected function requestReset(string $email): void
    {
        $this->postJson('https://api.url.infinityproject.fr/v1/auth/password/forgot', [
            'email' => $email,
        ])->assertStatus(202);
    }

    protected function verificationToken(): string
    {
        return $this->tokenFrom(VerifyEmail::class);
    }

    protected function resetToken(): string
    {
        return $this->tokenFrom(ResetPassword::class);
    }

    /**
     * @param  class-string<VerifyEmail|ResetPassword>  $mailable
     */
    private function tokenFrom(string $mailable): string
    {
        $url = (string) Mail::sent($mailable)->firstOrFail()->url;
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $token = $query['token'] ?? '';
        expect($token)->toBeString()->not->toBe('');

        return $token;
    }

    protected function linkDomain(): Domain
    {
        $this->seed(LinkDomainSeeder::class);

        return Domain::query()->where('hostname', 'iurl.fr')->firstOrFail();
    }

    protected function linkOn(Workspace $workspace, Domain $domain, string $slug): Link
    {
        return Link::factory()->forDomain($domain)->create([
            'workspace_id' => $workspace->id,
            'slug' => $slug,
        ]);
    }

    /**
     * Delete the account with a fresh dashboard token and return that token.
     */
    protected function deleteAccount(User $user): string
    {
        $token = $user->createToken('dashboard', ['*'])->plainTextToken;

        $this->withToken($token)
            ->deleteJson('https://api.url.infinityproject.fr/v1/me', [
                'password' => 'password',
            ])
            ->assertStatus(204);

        return $token;
    }
}
