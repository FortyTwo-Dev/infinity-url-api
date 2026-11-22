<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    private ?string $analyticsSaltDirectory = null;

    protected function setUp(): void
    {
        parent::setUp();

        // The analytics salt lives in a file, never in the database. Feature
        // tests get a disposable one, outside the repository.
        $directory = sys_get_temp_dir().'/iurl-analytics-salt-'.bin2hex(random_bytes(8));

        mkdir($directory, 0o700, true);

        $path = $directory.'/analytics.salt';
        file_put_contents($path, bin2hex(random_bytes(32)).PHP_EOL);
        chmod($path, 0o600);

        config(['analytics.salt_path' => $path]);
        $this->analyticsSaltDirectory = $directory;
    }

    protected function tearDown(): void
    {
        if ($this->analyticsSaltDirectory !== null) {
            foreach (glob($this->analyticsSaltDirectory.'/*') ?: [] as $file) {
                @unlink($file);
            }

            @rmdir($this->analyticsSaltDirectory);
            $this->analyticsSaltDirectory = null;
        }

        parent::tearDown();
    }
}
