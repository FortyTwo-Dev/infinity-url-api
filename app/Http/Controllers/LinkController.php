<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Links\CreateLink;
use App\Actions\Links\DeleteLink;
use App\Actions\Links\ListLinks;
use App\Actions\Links\UpdateLink;
use App\Http\Requests\Links\IndexLinksRequest;
use App\Http\Requests\Links\StoreLinkRequest;
use App\Http\Requests\Links\UpdateLinkRequest;
use App\Http\Resources\LinkResource;
use App\Models\User;
use App\Services\LinkLocator;
use App\Services\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use LogicException;

final class LinkController extends Controller
{
    public function index(
        IndexLinksRequest $request,
        WorkspaceContext $context,
        ListLinks $action,
    ): JsonResponse {
        $user = $this->user($request);
        $result = $action->handle($context->for($user), $request->validated());

        return response()->json([
            'data' => LinkResource::collection($result['data'])->resolve(),
            'next_cursor' => $result['next_cursor'],
            'has_more' => $result['has_more'],
        ]);
    }

    public function store(
        StoreLinkRequest $request,
        WorkspaceContext $context,
        CreateLink $action,
    ): JsonResponse {
        $user = $this->user($request);
        $link = $action->handle($user, $context->for($user), $request->validated());

        return (new LinkResource($link))
            ->response()
            ->setStatusCode(201);
    }

    public function show(
        Request $request,
        string $id,
        WorkspaceContext $context,
        LinkLocator $locator,
    ): LinkResource {
        $link = $locator->for($context->for($this->user($request)), $id);
        $this->authorize('view', $link);

        return new LinkResource($link);
    }

    public function update(
        UpdateLinkRequest $request,
        string $id,
        WorkspaceContext $context,
        LinkLocator $locator,
        UpdateLink $action,
    ): LinkResource {
        $link = $locator->for($context->for($this->user($request)), $id);
        $this->authorize('update', $link);

        return new LinkResource($action->handle($link, $request->validated()));
    }

    public function destroy(
        Request $request,
        string $id,
        WorkspaceContext $context,
        LinkLocator $locator,
        DeleteLink $action,
    ): Response {
        $link = $locator->for($context->for($this->user($request)), $id);
        $this->authorize('delete', $link);

        $action->handle($link);

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
