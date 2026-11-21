<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\WorkspaceResource;
use App\Models\User;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LogicException;

final class WorkspaceController extends Controller
{
    #[Response(
        status: 200,
        type: 'array{data: list<array{id: string, name: string, plan: string, limits: array{active_links: int, custom_domains: int, stats_retention_days: int}}>, next_cursor: string|null, has_more: bool}',
    )]
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new LogicException('An authenticated user is required.');
        }

        return response()->json([
            'data' => WorkspaceResource::collection($user->workspaces()->get())->resolve(),
            'next_cursor' => null,
            'has_more' => false,
        ]);
    }
}
