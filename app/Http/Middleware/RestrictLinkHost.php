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

        return $route->getName() === 'redirect'
            && is_string($slug)
            && rawurldecode($request->getPathInfo()) === '/'.$slug;
    }
}
