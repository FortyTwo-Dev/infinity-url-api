<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\Response;

final class ErrorPages
{
    public function notFound(): Response
    {
        return $this->render('errors.404', 404);
    }

    public function goneDeleted(): Response
    {
        return $this->render('errors.410-deleted', 410);
    }

    public function goneExpired(): Response
    {
        return $this->render('errors.410-expired', 410);
    }

    public function blocked(): Response
    {
        return $this->render('errors.403', 403);
    }

    private function render(string $view, int $status): Response
    {
        return response()->view($view, [], $status, [
            'X-Robots-Tag' => 'noindex',
        ]);
    }
}
