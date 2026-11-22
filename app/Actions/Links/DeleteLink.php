<?php

declare(strict_types=1);

namespace App\Actions\Links;

use App\Models\Link;
use App\Services\Analytics\StatsStore;
use Illuminate\Support\Facades\DB;

/**
 * A logical delete does not fire the foreign-key cascade, so the counters are
 * erased explicitly in the same transaction: deleting a link must delete its
 * audience, not just hide it. Hard deletes still cascade.
 */
final class DeleteLink
{
    public function __construct(private readonly StatsStore $stats) {}

    public function handle(Link $link): void
    {
        DB::transaction(function () use ($link): void {
            // Serialise with the analytics job: a click already in the queue
            // must see the deletion and abandon its counters.
            DB::table('links')->where('id', $link->getKey())->lockForUpdate()->first(['id']);

            $this->stats->forgetLink((int) $link->getKey());
            $link->delete();
        });
    }
}
