<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\DeviceClass;
use App\Services\Analytics\DeviceClassifier;
use PHPUnit\Framework\TestCase;

final class DeviceClassifierTest extends TestCase
{
    private const IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Mobile/15E148 Safari/604.1';

    private const IPAD = 'Mozilla/5.0 (iPad; CPU OS 18_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Mobile/15E148 Safari/604.1';

    private const DESKTOP_CHROME = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';

    private const GOOGLEBOT = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';

    private function classifier(): DeviceClassifier
    {
        return new DeviceClassifier;
    }

    public function test_chrome_on_windows_is_a_desktop_and_not_a_bot(): void
    {
        $observation = $this->classifier()->classify(self::DESKTOP_CHROME);

        $this->assertSame(DeviceClass::Desktop, $observation->device);
        $this->assertFalse($observation->isBot);
    }

    public function test_iphone_safari_is_mobile_and_not_a_bot(): void
    {
        $observation = $this->classifier()->classify(self::IPHONE);

        $this->assertSame(DeviceClass::Mobile, $observation->device);
        $this->assertFalse($observation->isBot);
    }

    public function test_ipad_safari_is_a_tablet_and_not_a_bot(): void
    {
        $observation = $this->classifier()->classify(self::IPAD);

        $this->assertSame(DeviceClass::Tablet, $observation->device);
        $this->assertFalse($observation->isBot);
    }

    public function test_googlebot_is_a_bot(): void
    {
        $observation = $this->classifier()->classify(self::GOOGLEBOT);

        $this->assertTrue($observation->isBot);
    }

    public function test_curl_is_unknown_and_not_a_bot(): void
    {
        $observation = $this->classifier()->classify('curl/8.5.0');

        $this->assertSame(DeviceClass::Unknown, $observation->device);
        $this->assertFalse($observation->isBot);
    }

    public function test_a_null_user_agent_is_unknown_and_not_a_bot(): void
    {
        $observation = $this->classifier()->classify(null);

        $this->assertSame(DeviceClass::Unknown, $observation->device);
        $this->assertFalse($observation->isBot);
    }

    public function test_an_empty_user_agent_is_unknown_and_not_a_bot(): void
    {
        $observation = $this->classifier()->classify('');

        $this->assertSame(DeviceClass::Unknown, $observation->device);
        $this->assertFalse($observation->isBot);
    }
}
