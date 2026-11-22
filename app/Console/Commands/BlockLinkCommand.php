<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Abuse\BlockLink;
use App\Models\Link;
use App\Models\Report;
use Illuminate\Console\Command;
use InvalidArgumentException;

final class BlockLinkCommand extends Command
{
    protected $signature = 'abuse:block-link
        {link : Public id or slug of the link}
        {--reason= : Motive sent to the owner}
        {--report= : Public id of the report this action resolves}';

    protected $description = 'Block a link for abuse, notify its owner and keep its statistics.';

    public function handle(BlockLink $action): int
    {
        try {
            $this->block($action);
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function block(BlockLink $action): void
    {
        $reason = trim((string) $this->option('reason'));

        if ($reason === '') {
            throw new InvalidArgumentException('A motive is required: --reason="..."');
        }

        $link = $this->link((string) $this->argument('link'));

        if ($link->trashed()) {
            throw new InvalidArgumentException('This link is deleted: blocking it would change nothing.');
        }

        $report = $this->report();
        $action->handle($link, $reason, $report);

        $this->info('Link '.$link->public_id.' blocked.');

        if ($report !== null) {
            $this->info('Report '.$report->public_id.' marked as actioned.');
        }
    }

    private function link(string $identifier): Link
    {
        $link = Link::withTrashed()->where('public_id', $identifier)->first()
            ?? Link::withTrashed()->where('slug', strtolower($identifier))->first();

        return $link ?? throw new InvalidArgumentException('Link not found: '.$identifier);
    }

    private function report(): ?Report
    {
        $identifier = $this->option('report');

        if (! is_string($identifier) || $identifier === '') {
            return null;
        }

        return Report::query()->where('public_id', $identifier)->first()
            ?? throw new InvalidArgumentException('Report not found: '.$identifier);
    }
}
