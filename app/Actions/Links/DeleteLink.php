<?php

declare(strict_types=1);

namespace App\Actions\Links;

use App\Models\Link;

final class DeleteLink
{
    public function handle(Link $link): void
    {
        $link->delete();
    }
}
