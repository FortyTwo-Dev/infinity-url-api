<?php

declare(strict_types=1);

namespace App\Support\Analytics;

use App\Enums\DeviceClass;

final readonly class DeviceObservation
{
    public function __construct(
        public DeviceClass $device,
        public bool $isBot,
    ) {}
}
