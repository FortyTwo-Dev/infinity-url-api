<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Enums\DeviceClass;
use App\Support\Analytics\DeviceObservation;
use DeviceDetector\DeviceDetector;
use DeviceDetector\Parser\Device\AbstractDeviceParser;

/**
 * Reduce the user-agent to a device class, in memory, then forget it.
 *
 * Only an identified bot is excluded; an unknown agent is counted as
 * `unknown` (docs/11 §5). No cache is ever configured: caching would keep a
 * user-agent derived key on disk or in Valkey.
 */
final class DeviceClassifier
{
    public function classify(?string $userAgent): DeviceObservation
    {
        $userAgent = trim((string) $userAgent);

        if ($userAgent === '') {
            return new DeviceObservation(DeviceClass::Unknown, false);
        }

        $detector = new DeviceDetector($userAgent);
        $detector->parse();

        if ($detector->isBot()) {
            return new DeviceObservation(DeviceClass::Unknown, true);
        }

        return new DeviceObservation($this->device($detector->getDevice()), false);
    }

    private function device(?int $type): DeviceClass
    {
        return match ($type) {
            AbstractDeviceParser::DEVICE_TYPE_SMARTPHONE,
            AbstractDeviceParser::DEVICE_TYPE_FEATURE_PHONE,
            AbstractDeviceParser::DEVICE_TYPE_PHABLET => DeviceClass::Mobile,
            AbstractDeviceParser::DEVICE_TYPE_TABLET => DeviceClass::Tablet,
            AbstractDeviceParser::DEVICE_TYPE_DESKTOP => DeviceClass::Desktop,
            default => DeviceClass::Unknown,
        };
    }
}
