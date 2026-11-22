<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\ErrorPages;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class RestrictLinkHost
{
    public function __construct(
        private readonly Router $router,
        private readonly ErrorPages $errors,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        return $this->isAllowed($request) ? $next($request) : $this->errors->notFound();
    }

    private function isAllowed(Request $request): bool
    {
        if ($request->getHost() !== (string) config('redirect.host')) {
            return true;
        }

        try {
            $route = $this->router->getRoutes()->match($request);
        } catch (NotFoundHttpException|MethodNotAllowedHttpException) {
            return false;
        }

        $slug = $route->parameter('slug');

        return is_string($slug)
            && $slug !== ''
            && $this->pathMatches($route->getName(), $slug, rawurldecode($request->getPathInfo()));
    }

    private function pathMatches(?string $name, string $slug, string $path): bool
    {
        return match ($name) {
            'redirect' => $path === '/'.$slug,
            'report.redirect' => $path === '/report/'.$slug,
            default => false,
        };
    }
}
