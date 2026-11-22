<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Abuse\BlockLink;
use App\Models\Link;
use App\Models\Report;
use Illuminate\Console\Command;

final class BlockLinkCommand extends Command
{
    protected $signature = 'abuse:block-link
        {link : Public id or slug of the link}
        {--reason= : Motive sent to the owner}
        {--report= : Public id of the report this action resolves}';

    protected $description = 'Block a link for abuse, notify its owner and keep its statistics.';

    public function handle(BlockLink $action): int
    {
        $reason = trim((string) $this->option('reason'));

        if ($reason === '') {
            $this->error('A motive is required: --reason="..."');

            return self::FAILURE;
        }

        $identifier = (string) $this->argument('link');

        $link = Link::withTrashed()
            ->where('public_id', $identifier)
            ->first()
            ?? Link::withTrashed()->where('slug', strtolower($identifier))->first();

        if ($link === null) {
            $this->error('Link not found: '.$identifier);

            return self::FAILURE;
        }

        if ($link->trashed()) {
            $this->error('This link is deleted: blocking it would change nothing.');

            return self::FAILURE;
        }

        $report = null;
        $reportId = $this->option('report');

        if (is_string($reportId) && $reportId !== '') {
            $report = Report::query()->where('public_id', $reportId)->first();

            if ($report === null) {
                $this->error('Report not found: '.$reportId);

                return self::FAILURE;
            }
        }

        $action->handle($link, $reason, $report);

        $this->info('Link '.$link->public_id.' blocked.');

        if ($report !== null) {
            $this->info('Report '.$report->public_id.' marked as actioned.');
        }

        return self::SUCCESS;
    }
}
