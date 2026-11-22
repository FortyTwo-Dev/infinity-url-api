<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Analytics\LinkStats;
use App\Http\Requests\Links\StatsRequest;
use App\Models\Link;
use App\Models\User;
use App\Services\LinkLocator;
use App\Services\WorkspaceContext;
use App\Support\Analytics\StatsRange;
use Carbon\CarbonImmutable;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use LogicException;

final class LinkStatsController extends Controller
{
    #[OpenApiResponse(
        status: 200,
        type: 'array{link_id: string, interval: string, from: string, to: string, retention_days: int, clamped: bool, summary: array{clicks: int}, series: list<array{date: string, clicks: int, uniques: int}|array{iso_year: int, iso_week: int, week_start: string, clicks: int, uniques: int}>, devices: list<array{device: string, clicks: int}>}',
        description: 'Daily or weekly buckets. Uniques are per bucket and never summed.',
    )]
    public function show(
        StatsRequest $request,
        string $id,
        WorkspaceContext $context,
        LinkLocator $locator,
        LinkStats $stats,
    ): JsonResponse {
        $link = $this->link($request, $id, $context, $locator);

        return response()->json($stats->handle($link, $this->range($request)));
    }

    #[OpenApiResponse(
        status: 200,
        type: 'string',
        mediaType: 'text/csv',
        description: 'CSV export of the same series, one row per bucket.',
    )]
    public function csv(
        StatsRequest $request,
        string $id,
        WorkspaceContext $context,
        LinkLocator $locator,
        LinkStats $stats,
    ): Response {
        $link = $this->link($request, $id, $context, $locator);
        $data = $stats->handle($link, $this->range($request));

        return response($this->csvBody($data), 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="stats-'.$link->public_id.'.csv"',
        ]);
    }

    /**
     * @param  array{interval: string, series: list<array<string, mixed>>}  $data
     */
    private function csvBody(array $data): string
    {
        $stream = fopen('php://temp', 'r+');

        if ($stream === false) {
            throw new LogicException('Unable to open a temporary stream.');
        }

        $weekly = $data['interval'] === 'week';

        fputcsv($stream, $weekly
            ? ['iso_year', 'iso_week', 'week_start', 'clicks', 'uniques']
            : ['date', 'clicks', 'uniques'], ',', '"', '');

        foreach ($data['series'] as $row) {
            fputcsv($stream, $weekly
                ? [
                    (int) $row['iso_year'],
                    (int) $row['iso_week'],
                    (string) $row['week_start'],
                    (int) $row['clicks'],
                    (int) $row['uniques'],
                ]
                : [
                    (string) $row['date'],
                    (int) $row['clicks'],
                    (int) $row['uniques'],
                ], ',', '"', '');
        }

        rewind($stream);
        $body = (string) stream_get_contents($stream);
        fclose($stream);

        return $body;
    }

    private function range(StatsRequest $request): StatsRange
    {
        return StatsRange::resolve(
            $request->validated(),
            CarbonImmutable::now('UTC'),
            (int) config('analytics.retention_days'),
            (int) config('analytics.default_window_days'),
            (int) config('analytics.max_buckets'),
        );
    }

    private function link(
        StatsRequest $request,
        string $id,
        WorkspaceContext $context,
        LinkLocator $locator,
    ): Link {
        $link = $locator->for($context->for($this->user($request)), $id);
        $this->authorize('view', $link);

        return $link;
    }

    private function user(StatsRequest $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new LogicException('An authenticated user is required.');
        }

        return $user;
    }
}
