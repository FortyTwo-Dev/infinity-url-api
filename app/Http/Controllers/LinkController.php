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
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use LogicException;

final class LinkController extends Controller
{
    #[OpenApiResponse(
        status: 200,
        type: 'array{data: list<array{id: string, slug: string, destination: string, short_url: string, status: string, expires_at: string|null, created_at: string|null, updated_at: string|null, clicks_7d?: int}>, next_cursor: string|null, has_more: bool}',
        description: '`clicks_7d` is present only when the include parameter asks for it.',
    )]
    public function index(
        IndexLinksRequest $request,
        WorkspaceContext $context,
        ListLinks $action,
    ): JsonResponse {
        $user = $this->user($request);
        $result = $action->handle($context->for($user), $request->validated());
        $data = LinkResource::collection($result['data'])->resolve();
        $clicks = $result['clicks_7d'];

        if (is_array($clicks)) {
            $data = array_map(static function (array $item) use ($clicks): array {
                $item['clicks_7d'] = $clicks[$item['id']] ?? 0;

                return $item;
            }, $data);
        }

        return response()->json([
            'data' => $data,
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
