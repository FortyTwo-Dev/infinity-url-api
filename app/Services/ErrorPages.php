<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\Response;

final class ErrorPages
{
    public function notFound(?string $slug = null): Response
    {
        return $this->render('errors.404', 404, $slug);
    }

    public function goneDeleted(?string $slug = null): Response
    {
        return $this->render('errors.410-deleted', 410, $slug);
    }

    public function goneExpired(?string $slug = null): Response
    {
        return $this->render('errors.410-expired', 410, $slug);
    }

    public function blocked(?string $slug = null): Response
    {
        return $this->render('errors.403', 403, $slug);
    }

    private function render(string $view, int $status, ?string $slug): Response
    {
        return response()->view($view, [
            'reportUrl' => $this->reportUrl($slug),
        ], $status, [
            'X-Robots-Tag' => 'noindex',
        ]);
    }

    /**
     * The footer of a terminal page is the only place where a victim is
     * already on our domain: it carries the slug to the report form.
     */
    private function reportUrl(?string $slug): ?string
    {
        if ($slug === null || $slug === '') {
            return null;
        }

        $base = rtrim((string) config('identity.dashboard_url'), '/');

        return $base.'/report?slug='.rawurlencode(strtolower($slug));
    }
}
