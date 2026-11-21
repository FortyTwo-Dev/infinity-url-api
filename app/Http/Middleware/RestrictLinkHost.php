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
        if ($request->getHost() !== (string) config('redirect.host')) {
            return $next($request);
        }

        try {
            $route = $this->router->getRoutes()->match($request);
        } catch (NotFoundHttpException|MethodNotAllowedHttpException) {
            return $this->errors->notFound();
        }

        if ($route->getName() !== 'redirect') {
            return $this->errors->notFound();
        }

        $slug = $route->parameter('slug');
        $path = rawurldecode($request->getPathInfo());

        if (! is_string($slug) || $path !== '/'.$slug) {
            return $this->errors->notFound();
        }

        return $next($request);
    }
}
