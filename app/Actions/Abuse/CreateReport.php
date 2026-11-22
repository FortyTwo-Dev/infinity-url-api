<?php

declare(strict_types=1);

namespace App\Actions\Abuse;

use App\Models\Link;
use App\Models\Report;
use App\Services\Abuse\LinkTarget;
use App\Services\Abuse\ReportNotifier;
use Illuminate\Validation\ValidationException;

/**
 * Writes one anonymous report.
 *
 * The link is resolved with `withTrashed()`: a soft-deleted link still exists
 * as a row and a report about it is worth keeping. A slug that does not exist
 * is not an error: the answer is still 202, otherwise the form becomes a tool
 * for mapping the links.
 */
final class CreateReport
{
    public function __construct(private readonly ReportNotifier $notifier) {}

    /**
     * @param  array{slug: string, category: string, details?: string|null, email?: string|null}  $data
     */
    public function handle(array $data): Report
    {
        $target = LinkTarget::parse((string) $data['slug'], (string) config('redirect.host'));

        if ($target === null) {
            throw ValidationException::withMessages([
                'slug' => ['The short link is invalid.'],
            ]);
        }

        $link = Link::withTrashed()
            ->where('slug', $target->slug)
            ->whereHas('domain', static fn ($query) => $query->where('hostname', $target->host))
            ->first();

        $report = Report::query()->create([
            'link_id' => $link?->id,
            'submitted_host' => $target->host,
            'submitted_slug' => $target->slug,
            'category' => $data['category'],
            'details' => $data['details'] ?? null,
            'contact_email' => $data['email'] ?? null,
            'state' => Report::NEW,
        ]);

        app()->terminating(fn () => $this->notifier->received($report));

        return $report;
    }
}
