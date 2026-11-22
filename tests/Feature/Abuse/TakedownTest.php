<?php

use App\Enums\LinkStatus;
use App\Mail\LinkBlocked;
use App\Models\Domain;
use App\Models\Link;
use App\Models\Report;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\LinkDomainSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;

/**
 * @return array{0: Workspace, 1: User, 2: Link}
 */
function takedownFixture(): array
{
    test()->seed(LinkDomainSeeder::class);

    $workspace = Workspace::factory()->create();
    $user = User::factory()->create();
    $workspace->members()->attach($user->id, ['role' => 'owner']);
    $domain = Domain::query()->where('hostname', 'iurl.fr')->firstOrFail();

    $link = Link::factory()->forDomain($domain)->create([
        'workspace_id' => $workspace->id,
        'created_by' => $user->id,
        'slug' => 'abused12',
        'destination' => 'https://phishing.example/login',
    ]);

    return [$workspace, $user, $link];
}

it('blocks a link, invalidates the cache, keeps the statistics and mails the motive', function () {
    Mail::fake();
    [, $user, $link] = takedownFixture();

    $this->get('https://iurl.fr/abused12')->assertStatus(302);

    DB::table('link_stats_daily')->updateOrInsert(
        ['link_id' => $link->id, 'date' => now()->toDateString()],
        ['clicks' => 7, 'uniques' => 3],
    );

    $report = Report::query()->create([
        'link_id' => $link->id,
        'submitted_host' => 'iurl.fr',
        'submitted_slug' => 'abused12',
        'category' => 'phishing',
        'state' => Report::NEW,
    ]);

    $this->artisan('abuse:block-link', [
        'link' => $link->public_id,
        '--reason' => 'Phishing page imitating a bank',
        '--report' => $report->public_id,
    ])->assertSuccessful();

    expect($link->fresh()?->status)->toBe(LinkStatus::Blocked)
        ->and($report->fresh()?->state)->toBe(Report::ACTIONED)
        ->and(DB::table('link_stats_daily')->where('link_id', $link->id)->value('clicks'))->toBe(7);

    Mail::assertSent(LinkBlocked::class, function (LinkBlocked $mail) use ($user): bool {
        return $mail->hasTo($user->email)
            && str_contains($mail->reason, 'Phishing page imitating a bank');
    });

    $this->get('https://iurl.fr/abused12')->assertStatus(403);
});

it('refuses to block without a motive', function () {
    takedownFixture();

    $this->artisan('abuse:block-link', ['link' => 'unknown'])
        ->expectsOutputToContain('A motive is required')
        ->assertFailed();
});

it('refuses to block a deleted link', function () {
    [, , $link] = takedownFixture();
    $link->delete();

    $this->artisan('abuse:block-link', [
        'link' => $link->public_id,
        '--reason' => 'late',
    ])->expectsOutputToContain('deleted')->assertFailed();
});

it('suspends an account without revoking its tokens or its export', function () {
    Mail::fake();
    [$workspace, $user] = takedownFixture();
    $user->createToken('export');
    $link = Link::factory()->forDomain(Domain::query()->firstOrFail())->create([
        'workspace_id' => $workspace->id,
    ]);

    $this->artisan('abuse:suspend-account', [
        'user' => $user->public_id,
        '--reason' => 'Repeated phishing links',
    ])->assertSuccessful();

    expect($user->fresh()?->isSuspended())->toBeTrue()
        ->and($user->tokens()->count())->toBe(1);

    Sanctum::actingAs($user->fresh(), ['links:read', 'links:write']);

    $this->postJson('https://api.url.infinityproject.fr/v1/links', [
        'destination' => 'https://example.com/new',
    ])->assertStatus(403)->assertJsonPath('code', 'account_suspended');

    expect($link->fresh())->not->toBeNull();

    Sanctum::actingAs($user, ['me:read']);

    $this->getJson('https://api.url.infinityproject.fr/v1/me/export')->assertOk();

    Mail::assertNothingSent();
});

it('keeps sign-in possible while suspended', function () {
    [, $user] = takedownFixture();
    $user->update(['suspended_at' => now()]);

    $this->postJson('https://api.url.infinityproject.fr/v1/auth/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertSuccessful();
});
