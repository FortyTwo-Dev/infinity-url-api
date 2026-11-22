<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Auth\ChangePassword;
use App\Actions\Auth\DeleteAccount;
use App\Actions\ExportPersonalData;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Http\Requests\Auth\DeleteAccountRequest;
use App\Models\User;
use App\Services\WorkspaceContext;
use App\Support\ApiData;
use App\Support\RefreshCookie;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use LogicException;

final class MeController extends Controller
{
    #[OpenApiResponse(
        status: 200,
        type: 'array{user: array{id: string, email: string, email_verified: bool, totp_enabled: bool}, workspace: array{id: string, name: string, plan: string, limits: array{active_links: int, custom_domains: int, stats_retention_days: int}}, capabilities: list<string>}',
    )]
    public function show(Request $request, WorkspaceContext $context): JsonResponse
    {
        $user = $this->user($request);

        return response()->json([
            'user' => ApiData::user($user),
            'workspace' => ApiData::workspace($context->for($user)),
            'capabilities' => config('identity.capabilities'),
        ]);
    }

    #[OpenApiResponse(
        status: 200,
        type: 'array{account: array{id: string, email: string, email_verified: bool, totp_enabled: bool, created_at: string|null}, workspace: array{id: string, name: string, plan: string, limits: array{active_links: int, custom_domains: int, stats_retention_days: int}}|null, links: list<array{id: string, slug: string, destination: string, short_url: string, status: string, expires_at: string|null, created_at: string|null, updated_at: string|null}>, statistics: list<array{link_id: string, daily: list<array{date: string, clicks: int, uniques: int}>, weekly: list<array{iso_year: int, iso_week: int, uniques: int}>, devices: list<array{device: string, clicks: int}>}>}',
    )]
    public function export(Request $request, ExportPersonalData $action): JsonResponse
    {
        return response()
            ->json($action->handle($this->user($request)))
            ->header('Content-Disposition', 'attachment; filename="infinity-url-export.json"');
    }

    public function password(ChangePasswordRequest $request, ChangePassword $action): Response
    {
        $data = $request->validated();

        $action->handle(
            $this->user($request),
            (string) $data['current_password'],
            (string) $data['password'],
            $this->refreshCookie($request),
        );

        return response()->noContent();
    }

    public function destroy(DeleteAccountRequest $request, DeleteAccount $action): Response
    {
        $data = $request->validated();

        $action->handle($this->user($request), (string) $data['password']);

        return response()->noContent()->withCookie(RefreshCookie::forget());
    }

    private function user(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new LogicException('An authenticated user is required.');
        }

        return $user;
    }

    private function refreshCookie(Request $request): ?string
    {
        $cookie = $request->cookie(RefreshCookie::NAME);

        return is_string($cookie) && $cookie !== '' ? $cookie : null;
    }
}
