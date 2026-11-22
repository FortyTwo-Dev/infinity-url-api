<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Exceptions\AnalyticsSaltMissingException;
use App\Exceptions\AnalyticsSaltRotationException;

/**
 * The analytics HMAC salt, read from a file only.
 *
 * It is never stored in the database, never in a backup, never in the
 * repository. `current()` re-reads the file when its stat stamp changes, so a
 * rotation is picked up without restarting the workers.
 */
final class AnalyticsSalt
{
    private ?string $memo = null;

    /**
     * @var array{path: string, mtime: int, inode: int, ctime: int, size: int}|null
     */
    private ?array $stamp = null;

    public function path(): string
    {
        return (string) config('analytics.salt_path');
    }

    public function current(): ?string
    {
        $path = $this->path();
        clearstatcache(true, $path);

        $stamp = $this->stamp($path);

        if ($stamp === null) {
            return $this->forget();
        }

        if ($this->memo !== null && $this->stamp === $stamp) {
            return $this->memo;
        }

        return $this->reload($path, $stamp);
    }

    /**
     * @return array{path: string, mtime: int, inode: int, ctime: int, size: int}|null
     */
    private function stamp(string $path): ?array
    {
        $stat = @stat($path);

        if ($stat === false) {
            return null;
        }

        return [
            'path' => $path,
            'mtime' => (int) $stat['mtime'],
            'inode' => (int) $stat['ino'],
            'ctime' => (int) $stat['ctime'],
            'size' => (int) $stat['size'],
        ];
    }

    /**
     * @param  array{path: string, mtime: int, inode: int, ctime: int, size: int}  $stamp
     */
    private function reload(string $path, array $stamp): ?string
    {
        $content = @file_get_contents($path);
        $content = is_string($content) ? trim($content) : '';

        if ($content === '') {
            return $this->forget();
        }

        $this->memo = $content;
        $this->stamp = $stamp;

        return $content;
    }

    private function forget(): null
    {
        $this->memo = null;
        $this->stamp = null;

        return null;
    }

    public function read(): string
    {
        return $this->current() ?? throw new AnalyticsSaltMissingException;
    }

    /**
     * Write a new secret to a temporary file, then rename it over the old one:
     * the previous value is unlinked in the same atomic step and no copy is kept.
     */
    public function rotate(): void
    {
        $path = $this->path();
        $directory = dirname($path);

        if (! is_dir($directory)) {
            throw new AnalyticsSaltRotationException('directory '.$directory.' does not exist');
        }

        $temporary = $path.'.tmp.'.bin2hex(random_bytes(6));
        $handle = @fopen($temporary, 'xb');

        if ($handle === false) {
            throw new AnalyticsSaltRotationException('cannot create '.$temporary);
        }

        try {
            $written = fwrite($handle, bin2hex(random_bytes(32)).PHP_EOL);

            if ($written === false) {
                throw new AnalyticsSaltRotationException('cannot write '.$temporary);
            }

            fflush($handle);
            @fsync($handle);
        } finally {
            fclose($handle);
        }

        if (! @chmod($temporary, 0o600)) {
            @unlink($temporary);

            throw new AnalyticsSaltRotationException('cannot restrict '.$temporary);
        }

        if (! @rename($temporary, $path)) {
            @unlink($temporary);

            throw new AnalyticsSaltRotationException('cannot replace '.$path);
        }

        $this->syncDirectory($directory);
        $this->memo = null;
        $this->stamp = null;
    }

    private function syncDirectory(string $directory): void
    {
        $handle = @fopen($directory, 'r');

        if ($handle === false) {
            return;
        }

        @fsync($handle);
        fclose($handle);
    }
}
