<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Link;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Read-only queue of the links flagged for manual review. Nothing here blocks
 * a link: a typosquat alone never blocks, a human decides.
 */
final class ReviewQueueCommand extends Command
{
    protected $signature = 'abuse:review-queue {--limit=50 : Maximum number of links to print}';

    protected $description = 'List the links waiting for a manual abuse review.';

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));

        /** @var Builder<Link> $query */
        $query = Link::query()
            ->with('domain')
            ->whereNotNull('review_required_at')
            ->orderBy('review_required_at')
            ->limit($limit);

        $links = $query->get();

        if ($links->isEmpty()) {
            $this->info('No link is waiting for review.');

            return self::SUCCESS;
        }

        $this->table(
            ['id', 'slug', 'destination', 'reason', 'flagged at'],
            $links->map(static fn (Link $link): array => [
                $link->public_id,
                $link->slug,
                $link->destination,
                $link->review_reason,
                $link->review_required_at?->toIso8601String(),
            ])->all(),
        );

        return self::SUCCESS;
    }
}
