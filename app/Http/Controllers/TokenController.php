<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Tokens\CreateApiToken;
use App\Actions\Tokens\ListApiTokens;
use App\Actions\Tokens\RevokeApiToken;
use App\Http\Requests\Tokens\IndexTokensRequest;
use App\Http\Requests\Tokens\StoreTokenRequest;
use App\Http\Resources\TokenResource;
use App\Models\User;
use App\Services\TokenLocator;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use LogicException;

final class TokenController extends Controller
{
    #[OpenApiResponse(
        status: 200,
        type: 'array{data: list<array{id: int, name: string, scopes: list<string>, last_used_at: string|null, expires_at: string|null, created_at: string|null}>, next_cursor: string|null, has_more: bool}',
    )]
    public function index(IndexTokensRequest $request, ListApiTokens $action): JsonResponse
    {
        $result = $action->handle($this->user($request), $request->validated());

        return response()->json([
            'data' => TokenResource::collection($result['data'])->resolve(),
            'next_cursor' => $result['next_cursor'],
            'has_more' => $result['has_more'],
        ]);
    }

    #[OpenApiResponse(
        status: 201,
        type: 'array{id: int, name: string, scopes: list<string>, last_used_at: string|null, expires_at: string|null, created_at: string|null, token: string}',
    )]
    public function store(StoreTokenRequest $request, CreateApiToken $action): JsonResponse
    {
        $data = $request->validated();

        $token = $action->handle(
            $this->user($request),
            (string) $data['name'],
            array_values((array) $data['scopes']),
            isset($data['expires_at']) ? (string) $data['expires_at'] : null,
        );

        $payload = (new TokenResource($token->accessToken))->resolve();
        $payload['token'] = $token->plainTextToken;

        return response()->json($payload, 201);
    }

    public function destroy(
        Request $request,
        string $id,
        TokenLocator $locator,
        RevokeApiToken $action,
    ): Response {
        $token = $locator->for($this->user($request), (int) $id);
        $this->authorize('delete', $token);
        $action->handle($token);

        return response()->noContent();
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
